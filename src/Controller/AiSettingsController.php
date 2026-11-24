<?php

declare(strict_types=1);
namespace App\Controller;

use App\Service\EmailDraftClientInterface;
use App\Service\EmailPreparationException;
use App\Service\EmailWritingProfileService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AiSettingsController
{
    public function __construct(private readonly EmailWritingProfileService $writingProfiles, private readonly EmailDraftClientInterface $client,
        private readonly CsrfTokenManagerInterface $csrf, private readonly UiTranslator $i18n,
        private readonly ?\App\Service\HunterContactProvider $hunter = null) {}

    #[Route('/settings/ai', name: 'studio_ai_settings', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $id = $request->query->all()['profile'] ?? null;
        if ($id !== null && !is_string($id)) return $this->render($request, null, [], ['form' => $this->message('profile_invalid', 'Choose a writing profile.')], null, 400);
        if ($request->query->get('new') === '1') $id = '';
        $messages = ['saved' => $this->copy('Schreibprofil gespeichert.', 'Writing profile saved.'), 'archived' => $this->copy('Schreibprofil archiviert. Seine Versionen bleiben erhalten.', 'Writing profile archived. Its revisions are retained.'),
            'default' => $this->copy('Standardprofil gespeichert. Bereits geöffnete Entwürfe behalten ihr gewähltes Profil.', 'Default profile saved. Existing drafts keep their selected profile.')];
        $code = $request->query->all()['message'] ?? '';
        return $this->render($request, $id, [], [], is_string($code) ? ($messages[$code] ?? null) : null);
    }

    #[Route('/settings/ai', name: 'studio_ai_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $input = $request->request->all();
        $id = is_string($input['profileId'] ?? null) ? $input['profileId'] : '';
        $values = in_array($input['action'] ?? null, ['create', 'update'], true) ? $this->formValues($input) : [];
        try {
            if (strlen($request->getContent()) > 524288) throw new EmailPreparationException('profile_limit', 'The submitted writing profile is too large.', 413);
            if (!is_string($input['_token'] ?? null) || !$this->csrf->isTokenValid(new CsrfToken('ai_settings', $input['_token']))) throw new EmailPreparationException('invalid_csrf', 'Invalid form. Reload the page before saving.', 403);
            $action = $input['action'] ?? null;
            if (in_array($action, ['create', 'update'], true)) {
                $profile = $this->writingProfiles->save($input);
                return $this->redirect($profile['id'], 'saved');
            }
            if ($action === 'archive') {
                $this->writingProfiles->archive($id, $this->revision($input['revision'] ?? null));
                return $this->redirect($id, 'archived');
            }
            if ($action === 'default') {
                $this->writingProfiles->setDefault($id, $this->revision($input['revision'] ?? null), $this->revision($input['defaultRevision'] ?? null));
                return $this->redirect($id, 'default');
            }
            throw new EmailPreparationException('profile_invalid', 'Choose a writing profile action.', 400);
        } catch (EmailPreparationException $error) {
            $field = match ($error->errorCode) { 'profile_name' => 'name', 'profile_examples' => 'examples', default => 'form' };
            return $this->render($request, $id, $values, [$field => $this->message($error->errorCode, $error->getMessage())], null, $error->httpStatus);
        }
    }

    private function render(Request $request, ?string $id, array $values = [], array $errors = [], ?string $message = null, int $status = 200): Response
    {
        $locale = $this->i18n->locale();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $languageReturnPath = $request->getRequestUri();
        $csrfToken = $this->csrf->getToken('ai_settings')->getValue();
        $available = $this->writingProfiles->available();
        $profiles = $this->writingProfiles->summaries(true);
        $selection = $this->writingProfiles->defaultSelection();
        $defaultProfileId = $selection['id']; $defaultRevision = $selection['revision'];
        $selectedProfile = null;
        $id ??= $defaultProfileId;
        if ($id !== '') {
            try { $selectedProfile = $this->writingProfiles->get($id, true); }
            catch (EmailPreparationException $error) { $errors['form'] ??= $this->message($error->errorCode, $error->getMessage()); $status = $status === 200 ? 404 : $status; }
        }
        $profileHistory = $selectedProfile === null ? [] : $this->writingProfiles->history($selectedProfile['id']);
        $formValues = $values ?: ['profileId' => $selectedProfile['id'] ?? '', 'revision' => $selectedProfile['revision'] ?? '', 'name' => $selectedProfile['name'] ?? '',
            'instructions' => $selectedProfile['instructions'] ?? '', 'example_names' => array_column($selectedProfile['examples'] ?? [], 'name'), 'example_texts' => array_column($selectedProfile['examples'] ?? [], 'text')];
        $retainedEdit = $values !== [] && $errors !== [];
        $connection = ['configured' => $this->client->configured(), 'model' => $this->client->model(), 'reasoning' => $this->client->reasoning(), 'hunterConfigured' => $this->hunter?->configured() ?? false];
        $hunterConnection = ['configured' => $this->hunter?->configured() ?? false];
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/ai-settings.php';
        return new Response(ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function redirect(string $id, string $message): RedirectResponse { return new RedirectResponse('/settings/ai?'.http_build_query(['profile' => $id, 'message' => $message], '', '&', PHP_QUERY_RFC3986), 303, ['Cache-Control' => 'no-store']); }
    private function revision(mixed $value): int
    {
        if ((is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]{0,8}$/D', $value))) return (int)$value;
        throw new EmailPreparationException('profile_stale', 'The profile revision is missing. Reload before saving.', 409);
    }
    private function formValues(array $input): array
    {
        $values = [];
        foreach (['profileId', 'revision', 'name', 'instructions'] as $field) $values[$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
        foreach (['example_names', 'example_texts'] as $field) $values[$field] = is_array($input[$field] ?? null) ? array_values(array_filter($input[$field], 'is_string')) : [];
        return $values;
    }
    private function copy(string $de, string $en): string { return $this->i18n->locale() === 'de' ? $de : $en; }
    private function message(string $code, string $fallback): string
    {
        if ($this->i18n->locale() !== 'de') return $fallback;
        return match ($code) {
            'invalid_csrf' => 'Das Formular ist abgelaufen oder ungültig. Lade die Seite vor dem Speichern neu.',
            'profile_name' => 'Gib dem Schreibprofil einen Namen.',
            'profile_examples' => 'Verwende höchstens drei Beispiele, jeweils mit Name und Mailtext.',
            'profile_read_only' => 'Das mitgelieferte Profil ist schreibgeschützt. Lege ein eigenes Profil an.',
            'profile_stale' => 'Das Profil oder die Standardauswahl wurde inzwischen geändert. Lade den aktuellen Stand vor dem Speichern neu; deine Eingaben bleiben hier erhalten.',
            'profile_missing' => 'Das gewählte Schreibprofil ist nicht verfügbar. Wähle ein vorhandenes Profil.',
            'profile_is_default' => 'Wähle vor dem Archivieren zuerst ein anderes Standardprofil.',
            'profile_migration_required' => 'Führe zuerst die Datenbankmigration für Schreibprofile aus.',
            'profile_limit' => 'Das Größenlimit ist erreicht. Erlaubt sind 50 aktive Profile und höchstens 128 KiB pro Profil.',
            default => 'Prüfe Feldlängen und Textformat des Schreibprofils.',
        };
    }
}
