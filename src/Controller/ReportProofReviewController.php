<?php

namespace App\Controller;

use App\Service\FindingService;
use App\Service\ReportProjectionService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Separate explicit evidence attribution; a normal manual verdict is not XSS proof. */
final class ReportProofReviewController
{
    public function __construct(private readonly FindingService $findings, private readonly ReportProjectionService $reports, private readonly CsrfTokenManagerInterface $csrf) {}

    #[Route('/findings/{id}/report-proof-review', name: 'finding_report_proof_review', methods: ['POST'])]
    public function review(string $id, Request $request): Response
    {
        $input = $request->request->all();
        foreach (['_token','proof_id','basis_revision','reason'] as $key) {
            if (!is_string($input[$key] ?? null)) return new Response('Invalid evidence review.', 400);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('report_proof_review_'.$id, $input['_token']))) return new Response('Invalid form.', 403);
        if (!ctype_digit($input['basis_revision']) || strlen($input['reason']) > 4000) return new Response('Invalid review basis.', 400);
        try {
            $finding = $this->findings->getFindingOrFail($id);
            if ($finding->isDiscarded() || !$this->reports->available()) return new Response('Finding is not available for evidence review.', 409);
            $this->reports->reviewWorkerProof($finding, $input['proof_id'], (int)$input['basis_revision'], 'local-review', $input['reason']);
        } catch (\InvalidArgumentException $error) {
            return new Response($error->getMessage(), 400);
        } catch (\UnexpectedValueException $error) {
            return new Response($error->getMessage(), 409);
        }
        return new RedirectResponse('/findings/'.rawurlencode($id).'#worker-proof-review', 303);
    }
}
