<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\EmailPreparationException;
use App\Service\EmailPreparationService;
use App\Service\GmailDraftService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class EmailPreparationController
{
    public function __construct(private readonly FindingRepository $findings, private readonly EmailPreparationService $emails,
        private readonly CsrfTokenManagerInterface $csrf, private readonly UiTranslator $i18n, private readonly GmailDraftService $gmail) {}

    #[Route('/findings/{id}/prepare-email', name: 'finding_prepare_email', methods: ['GET'])]
    public function page(string $id, Request $request): Response
    {
        $finding = Uuid::isValid($id) ? $this->findings->find($id) : null;
        if (!$finding instanceof Finding) return new Response($this->i18n->trans('Fall nicht gefunden.'), 404, ['Cache-Control' => 'no-store']);
        $locale = $this->i18n->locale();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $languageReturnPath = $request->getRequestUri();
        $csrfToken = $this->csrf->getToken('email_prepare_'.$id)->getValue();
        $initialState = $this->emails->initialState($finding, $locale);
        $initialState['gmail'] = $this->gmail->state($finding);
        if ($initialState['error'] !== null) $initialState['error'] = $this->publicMessage($initialState['code'] ?? '', $initialState['error']);
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/prepare-email.php';
        return new Response(ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    #[Route('/findings/{id}/prepare-email/preview', name: 'finding_prepare_email_preview', methods: ['POST'])]
    public function preview(string $id, Request $request): Response { return $this->action($id, $request, false); }

    #[Route('/findings/{id}/prepare-email/generate', name: 'finding_prepare_email_generate', methods: ['POST'])]
    public function generate(string $id, Request $request): Response { return $this->action($id, $request, true); }

    private function action(string $id, Request $request, bool $generate): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 8192 || $request->getContentTypeFormat() !== 'json') throw new EmailPreparationException('invalid_input', 'Invalid request. Reload the page.', 400);
            try { $input = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new EmailPreparationException('invalid_input', 'Invalid request. Reload the page.', 400); }
            if (!is_array($input) || array_is_list($input) || array_diff(array_keys($input), ['_token', 'language', 'fingerprint', 'profileId'])
                || !is_string($input['_token'] ?? null) || !is_string($input['language'] ?? null)
                || (array_key_exists('profileId', $input) && !is_string($input['profileId']))
                || ($generate && !is_string($input['fingerprint'] ?? null))) throw new EmailPreparationException('invalid_input', 'Invalid request. Reload the page.', 400);
            if (!$this->csrf->isTokenValid(new CsrfToken('email_prepare_'.$id, $input['_token']))) throw new EmailPreparationException('invalid_csrf', 'Invalid form. Reload the page.', 403);
            $finding = Uuid::isValid($id) ? $this->findings->find($id) : null;
            if (!$finding instanceof Finding) throw new EmailPreparationException('not_found', 'Case not found.', 404);
            // Release PHP's session lock before the provider call, keeping Review usable in another tab.
            if ($request->hasSession() && $request->getSession()->isStarted()) $request->getSession()->save();
            $profileId = $input['profileId'] ?? \App\Service\EmailWritingProfileService::BUILTIN;
            $result = $generate ? $this->emails->generate($finding, $input['language'], $input['fingerprint'], $profileId) : $this->emails->preview($finding, $input['language'], $profileId);
            return $this->json(['ok' => true] + $result);
        } catch (EmailPreparationException $error) {
            return $this->json(['ok' => false, 'error' => $this->publicMessage($error->errorCode, $error->getMessage()), 'code' => $error->errorCode], $error->httpStatus);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'error' => $this->publicMessage('source_unavailable', 'The current report information could not be read. Reload the case and review its findings.'), 'code' => 'source_unavailable'], 503);
        }
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store']);
    }

    private function publicMessage(string $code, string $fallback): string
    {
        if ($this->i18n->locale() !== 'de') return $fallback;
        return match ($code) {
            'not_configured' => 'Hinterlege zuerst einen OpenAI-API-Schlüssel und ein unterstütztes E-Mail-Modell auf dem Server.',
            'invalid_input', 'invalid_csrf' => 'Ungültige Anfrage. Bitte lade die Seite neu.',
            'invalid_language' => 'Wähle Deutsch oder Englisch.',
            'not_found' => 'Fall nicht gefunden.',
            'stale_preview' => 'Fall, Kontakt oder Anfrage haben sich geändert. Zeige vor dem nächsten Generieren eine neue Vorschau an.',
            'profile_missing', 'profile_stale' => 'Das Schreibprofil wurde geändert oder archiviert. Wähle ein verfügbares Profil und zeige die Anfrage erneut an.',
            'projection_stale', 'projection_ineligible', 'no_components', 'unsupported_component' => 'Die ausgewählten Erkenntnisse sind noch nicht für eine Meldung freigegeben. Bitte prüfe den Fall zuerst.',
            'workflow_blocked' => 'Für diesen Fall kann kein neuer Erstkontakt vorbereitet werden. Prüfe Status und Meldeverlauf.',
            'contact_blocked' => 'Die Kontaktaufnahme ist gesperrt oder der Fall wurde geändert. Prüfe zuerst die Kontaktsperren.',
            'contact_missing' => 'Wähle zuerst einen Meldekontakt oder ein Meldeformular im Fall aus.',
            'contact_expired' => 'Die Quelle des gewählten Kontakts ist abgelaufen. Prüfe und wähle einen aktuellen Meldeweg.',
            'poc_changed', 'invalid_host' => 'Der Original-PoC fehlt oder wurde geändert. Lade den Fall neu und prüfe die Fundstelle.',
            'provider_limit' => 'OpenAI konnte die Anfrage nicht annehmen. Prüfe dein API-Kontingent und versuche es später erneut.',
            'refused_output' => 'OpenAI hat die Erstellung abgelehnt. Du kannst die Meldung manuell verfassen.',
            'incomplete_output' => 'OpenAI hat den Entwurf nicht fertiggestellt. Es wurde nichts gespeichert; versuche es bei Bedarf erneut.',
            'unsafe_prose' => 'Die generierte Einleitung enthielt nicht belegte Details. Es wurde kein Entwurf übernommen; versuche es erneut oder verfasse ihn manuell.',
            'provider_error', 'invalid_output' => 'Die API-Anfrage oder Antwort konnte nicht abgeschlossen werden. Prüfe die Vorschau und Serverkonfiguration, bevor du es erneut versuchst.',
            default => 'Die aktuellen Fallinformationen konnten nicht gelesen werden. Lade den Fall neu und prüfe seine Erkenntnisse.',
        };
    }
}
