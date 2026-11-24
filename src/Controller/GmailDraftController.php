<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\EmailPreparationException;
use App\Service\GmailDraftService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class GmailDraftController
{
    public function __construct(private readonly FindingRepository $findings, private readonly GmailDraftService $gmail,
        private readonly CsrfTokenManagerInterface $csrf, private readonly UiTranslator $i18n) {}

    #[Route('/findings/{id}/prepare-email/gmail-draft', name: 'finding_gmail_draft_state', methods: ['GET'])]
    public function state(string $id): JsonResponse
    {
        $finding = Uuid::isValid($id) ? $this->findings->find($id) : null;
        if (!$finding instanceof Finding) return $this->json(['ok' => false, 'code' => 'not_found', 'error' => $this->message('not_found', 'Case not found.')], 404);
        try { return $this->json(['ok' => true] + $this->localized($this->gmail->state($finding))); }
        catch (\Throwable) { return $this->json(['ok' => false, 'code' => 'gmail_uncertain', 'error' => $this->message('gmail_uncertain', 'The local Gmail receipt could not be read. Do not start another attempt.'), 'canCreate' => false], 503); }
    }

    #[Route('/findings/{id}/prepare-email/gmail-draft', name: 'finding_gmail_draft_create', methods: ['POST'])]
    public function create(string $id, Request $request): JsonResponse { return $this->action($id, $request, false); }

    #[Route('/findings/{id}/prepare-email/gmail-draft/reconcile', name: 'finding_gmail_draft_reconcile', methods: ['POST'])]
    public function reconcile(string $id, Request $request): JsonResponse { return $this->action($id, $request, true); }

    private function action(string $id, Request $request, bool $reconcile): JsonResponse
    {
        $finding = null;
        $input = [];
        try {
            if (strlen($request->getContent()) > 163840 || $request->getContentTypeFormat() !== 'json') throw new EmailPreparationException('gmail_invalid_input', 'Invalid draft request.', 400);
            try { $input = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new EmailPreparationException('gmail_invalid_input', 'Invalid draft request.', 400); }
            $allowed = $reconcile ? ['_token', 'attemptId'] : ['_token', 'attemptId', 'expectedAccount', 'language', 'fingerprint', 'subject', 'body', 'reviewed', 'profileId'];
            if (!is_array($input) || array_is_list($input) || array_diff(array_keys($input), $allowed) || !is_string($input['_token'] ?? null)
                || !is_string($input['attemptId'] ?? null)) throw new EmailPreparationException('gmail_invalid_input', 'Invalid draft request.', 400);
            if (!$this->csrf->isTokenValid(new CsrfToken('email_prepare_'.$id, $input['_token']))) throw new EmailPreparationException('invalid_csrf', 'Invalid form. Reload the page.', 403);
            $finding = Uuid::isValid($id) ? $this->findings->find($id) : null;
            if (!$finding instanceof Finding) throw new EmailPreparationException('not_found', 'Case not found.', 404);
            if ($request->hasSession() && $request->getSession()->isStarted()) $request->getSession()->save();
            $receipt = $reconcile ? $this->gmail->reconcile($finding, $input['attemptId']) : $this->gmail->create($finding, $input);
            // ok means the attempt has a durable receipt, not that Gmail verification succeeded.
            return $this->json(['ok' => true] + $this->localized($receipt));
        } catch (EmailPreparationException $error) {
            try { $state = $finding instanceof Finding ? $this->gmail->state($finding) : ['canCreate' => false, 'receipt' => null]; }
            catch (\Throwable) { $state = ['canCreate' => false, 'receipt' => null]; }
            return $this->json(['ok' => false, 'error' => $this->message($error->errorCode, $error->getMessage()), 'code' => $error->errorCode,
                'canCreate' => $state['canCreate'], 'receipt' => $this->localized($state['receipt'])], $error->httpStatus);
        } catch (\Throwable) {
            // A provider may have completed before local receipt persistence failed. Never advise a new create.
            return $this->json(['ok' => false, 'error' => $this->message('gmail_uncertain', 'The Gmail result is uncertain. Reload its status and check the recorded attempt.'),
                'code' => 'gmail_uncertain', 'canCreate' => false, 'attemptId' => is_string($input['attemptId'] ?? null) && Uuid::isValid($input['attemptId']) ? $input['attemptId'] : null], 503);
        }
    }

    private function localized(?array $data): ?array
    {
        if ($data === null) return null;
        if (is_array($data['receipt'] ?? null)) $data['receipt'] = $this->localized($data['receipt']);
        if ($this->i18n->locale() === 'de' && is_string($data['error'] ?? null)) {
            $data['error'] = ($data['safeToRetry'] ?? false)
                ? 'Bei diesem Versuch wurde kein Gmail-Entwurf erstellt. Behebe die Verbindung oder aktualisiere den Fall und starte bei Bedarf einen neuen Versuch.'
                : (!empty($data['draftId']) ? 'Ein Gmail-Entwurf wurde erstellt, sein aktueller Inhalt konnte aber nicht bestätigt werden. Prüfe diesen Versuch; lege keinen weiteren Entwurf an.'
                    : 'Das Gmail-Ergebnis ist unklar. Prüfe diesen Versuch; lege keinen weiteren Entwurf an.');
        }
        return $data;
    }

    private function json(array $data, int $status = 200): JsonResponse { return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']); }
    private function message(string $code, string $fallback): string
    {
        if ($this->i18n->locale() !== 'de') return $fallback;
        return match ($code) {
            'not_found' => 'Fall nicht gefunden.',
            'invalid_csrf' => 'Ungültiges Formular. Bitte lade die Seite neu.',
            'gmail_not_configured', 'gmail_unavailable' => 'Richte zuerst die lokale Gmail-Verbindung und das erwartete Konto ein.',
            'gmail_migration_required' => 'Führe zuerst die Datenbankmigration für Gmail-Entwurfsbelege aus.',
            'gmail_review_required' => 'Prüfe die vollständige E-Mail und bestätige sie vor der Gmail-Ablage.',
            'gmail_attempt_exists' => 'Für diesen Fall gibt es bereits einen Gmail-Versuch. Prüfe diesen, statt einen weiteren Entwurf anzulegen.',
            'gmail_attempt_missing' => 'Kein passender Gmail-Versuch gespeichert.',
            'gmail_account_changed' => 'Das konfigurierte Gmail-Konto wurde geändert. Lade die Seite neu und prüfe das Konto vor der Ablage.',
            'gmail_poc_missing' => 'Der vollständige, unveränderte Original-PoC muss im fertigen Mailtext bleiben.',
            'gmail_form_route' => 'Der gewählte Meldeweg ist ein Formular. Verwende den Einreichungstext, statt einen Gmail-Entwurf anzulegen.',
            'gmail_invalid_claims' => 'Entferne interne Fallinformationen und behalte die Einordnung der ausgewählten Erkenntnisse bei.',
            'gmail_invalid_input', 'gmail_invalid_text' => 'Prüfe Betreff und Mailtext. Der Betreff muss einzeilig sein, der Text darf höchstens 64 KiB groß sein.',
            'stale_preview' => 'Fall oder Kontakt haben sich geändert. Zeige eine neue Vorschau an und prüfe den Entwurf erneut.',
            'gmail_uncertain' => 'Das Gmail-Ergebnis ist unklar. Lade den Status neu und prüfe den gespeicherten Versuch; lege keinen weiteren Entwurf an.',
            default => 'Der Gmail-Entwurf kann mit diesem Fallstand nicht angelegt werden. Prüfe Fall, Meldeweg und den bestehenden Versuch.',
        };
    }
}
