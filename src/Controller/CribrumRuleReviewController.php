<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CribrumRuleReviewService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CribrumRuleReviewController
{
    public function __construct(
        private readonly CribrumRuleReviewService $rules,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UiTranslator $i18n,
    ) {}

    #[Route('/settings/cribrum/rule-review', name: 'cribrum_rule_review', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = $request->query->all();
        $status = $query['status'] ?? 'pending';
        $page = $query['page'] ?? '1';
        if (!in_array($status, ['pending', 'reviewed'], true)
            || !is_string($page) || preg_match('/^[1-9][0-9]{0,5}$/D', $page) !== 1) {
            return new Response($this->i18n->trans('Ungültige Auswahl.'), 400);
        }
        return $this->render($status, (int) $page);
    }

    #[Route('/settings/cribrum/rule-review/{id}', name: 'cribrum_rule_review_resolve', methods: ['POST'])]
    public function resolve(string $id, Request $request): Response
    {
        $input = $request->request->all();
        if (!Uuid::isValid($id) || !is_string($input['_token'] ?? null)
            || !is_string($input['revision'] ?? null) || preg_match('/^[1-9][0-9]{0,8}$/D', $input['revision']) !== 1
            || !is_string($input['note'] ?? null) || strlen($input['note']) > 2000) {
            return $this->render('pending', 1, $this->i18n->trans('Ungültige Prüfnotiz.'), 400);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('cribrum_rule_review_'.$id, $input['_token']))) {
            return $this->render('pending', 1, $this->i18n->trans('Die Prüfung wurde nicht gespeichert. Formular bitte neu laden.'), 403);
        }
        try {
            $this->rules->resolve($id, (int) $input['revision'], trim($input['note']) ?: null);
        } catch (\UnexpectedValueException) {
            return $this->render('pending', 1, $this->i18n->trans('Dieser Regelhinweis wurde inzwischen geändert. Bitte neu laden.'), 409);
        } catch (\InvalidArgumentException) {
            return $this->render('pending', 1, $this->i18n->trans('Ungültige Prüfnotiz.'), 400);
        }
        return new RedirectResponse('/settings/cribrum/rule-review?status=reviewed', 303);
    }

    private function render(string $status, int $page, ?string $error = null, int $httpStatus = 200): Response
    {
        $available = $this->rules->available();
        $result = $available ? $this->rules->list($status, 25, ($page - 1) * 25) : ['rows' => [], 'total' => 0];
        $locale = $this->i18n->locale();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $formatTime = fn (?\DateTimeInterface $at, bool $seconds = false): string => $this->i18n->formatDateTime($at, $seconds);
        $i18nJson = $this->i18n->browserCatalogJson();
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/rule-review.php';
        return new Response((string) ob_get_clean(), $available ? $httpStatus : 503, ['Cache-Control' => 'no-store']);
    }
}
