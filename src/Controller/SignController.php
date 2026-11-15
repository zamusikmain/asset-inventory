<?php

namespace App\Controller;

use App\Entity\HandoverDocument;
use App\Service\HandoverService;
use App\Service\SignLinkService;
use App\Util\HeaderUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Подписание акта сотрудником по ссылке из уведомления (без учетной записи в системе).
 * Доступ — только из корпоративной сети (nginx) и по HMAC-токену.
 */
#[Route('/sign/{id}/{token}')]
class SignController extends AbstractController
{
    public function __construct(private readonly SignLinkService $links, private readonly HandoverService $handover)
    {
    }

    private function check(HandoverDocument $doc, string $token): void
    {
        if (!$this->links->isValid($doc, $token) || $doc->getType() === HandoverDocument::TYPE_INVENTORY) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('', name: 'sign_document', methods: ['GET'])]
    public function page(HandoverDocument $doc, string $token): Response
    {
        $this->check($doc, $token);

        return $this->render('sign/page.html.twig', ['doc' => $doc, 'token' => $token]);
    }

    #[Route('/pdf', name: 'sign_document_pdf', methods: ['GET'])]
    public function pdf(HandoverDocument $doc, string $token): Response
    {
        $this->check($doc, $token);

        return new Response($this->handover->pdfContent($doc), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => HeaderUtils::disposition($doc->getNumber() . '.pdf', true)]);
    }

    /** Base64 PDF для передачи в NCALayer (createCAdESFromBase64). */
    #[Route('/data', name: 'sign_document_data', methods: ['GET'])]
    public function data(HandoverDocument $doc, string $token): Response
    {
        $this->check($doc, $token);

        return $this->json(['data' => base64_encode($this->handover->pdfContent($doc)), 'sha256' => $doc->getPdfSha256()]);
    }

    #[Route('/submit', name: 'sign_document_submit', methods: ['POST'])]
    public function submit(HandoverDocument $doc, string $token, Request $r): Response
    {
        $this->check($doc, $token);
        if (!$r->headers->has('X-Requested-With')) {
            throw $this->createAccessDeniedException();
        }
        $sig = $this->handover->attachSignature($doc, (string) ($r->toArray()['cms'] ?? ''), 'sign_link');

        return $this->json([
            'status' => $doc->getStatus(),
            'status_label' => $doc->getStatusLabel(),
            'verification' => $sig->getVerificationStatus(),
            'label' => $sig->getVerificationLabel(),
            'subject' => $sig->getCertSubject(),
        ]);
    }
}
