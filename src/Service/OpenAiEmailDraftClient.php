<?php

declare(strict_types=1);
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A single explicitly requested Responses call. No retries, tools or redirects. */
final class OpenAiEmailDraftClient implements EmailDraftClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?string $apiKey = null,
        private readonly string $model = 'gpt-6.2-sol',
        private readonly string $reasoning = 'medium',
    ) {}

    public function configured(): bool
    {
        return is_string($this->apiKey) && preg_match('/^[\x21-\x7e]{10,512}$/D', $this->apiKey) === 1
            && in_array($this->model, ['gpt-6.2-sol', 'gpt-6.2-astra'], true)
            && in_array($this->reasoning, ['low', 'medium', 'high', 'xhigh', 'max'], true);
    }

    public function model(): string { return $this->model; }
    public function reasoning(): string { return $this->reasoning; }

    public function generate(array $request): array
    {
        if (!$this->configured()) throw new EmailPreparationException('not_configured', 'Configure an OpenAI API key and a supported email model on the server first.', 503);
        try {
            $response = $this->client->request('POST', 'https://api.openai.com/v1/responses', [
                'auth_bearer' => $this->apiKey, 'json' => $request,
                'headers' => ['Accept' => 'application/json'], 'max_redirects' => 0,
                'timeout' => 30, 'max_duration' => 90, 'verify_peer' => true, 'verify_host' => true,
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > 262144) throw new \UnexpectedValueException('Response exceeds the draft size limit.');
                },
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $response->cancel();
                throw new EmailPreparationException($status === 429 ? 'provider_limit' : 'provider_error',
                    $status === 429 ? 'OpenAI could not accept the request. Check your API quota and try again when ready.' : 'OpenAI could not complete the request. Check the server configuration and try again when ready.', 502);
            }
            $raw = $response->getContent(false);
            if (strlen($raw) > 262144) throw new EmailPreparationException('invalid_output', 'The generated answer was not usable. Preview the request and try again when ready.', 502);
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['status'] ?? null) !== 'completed' || isset($data['error']) || !empty($data['incomplete_details'])) {
                throw new EmailPreparationException('incomplete_output', 'OpenAI did not finish the draft. Nothing was saved; try again when ready.', 502);
            }
            $texts = [];
            foreach ($data['output'] ?? [] as $item) {
                if (!is_array($item)) throw new \UnexpectedValueException();
                if (($item['type'] ?? '') === 'reasoning') continue;
                if (($item['type'] ?? '') !== 'message' || ($item['role'] ?? '') !== 'assistant' || ($item['status'] ?? '') !== 'completed') throw new \UnexpectedValueException();
                foreach ($item['content'] ?? [] as $content) {
                    if (($content['type'] ?? '') === 'refusal') throw new EmailPreparationException('refused_output', 'OpenAI declined to prepare this draft. You can edit a report manually.', 422);
                    if (($content['type'] ?? '') !== 'output_text' || !is_string($content['text'] ?? null)) throw new \UnexpectedValueException();
                    $texts[] = $content['text'];
                }
            }
            if (count($texts) !== 1) throw new \UnexpectedValueException();
            $draft = json_decode($texts[0], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($draft) || count($draft) !== 3 || array_diff(['subject', 'opening', 'closing'], array_keys($draft))) throw new \UnexpectedValueException();
            foreach (['subject' => 180, 'opening' => 1200, 'closing' => 600] as $field => $limit) {
                if (!is_string($draft[$field]) || trim($draft[$field]) === '' || mb_strlen($draft[$field]) > $limit
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $draft[$field])
                    || ($field === 'subject' && preg_match('/[\r\n]/', $draft[$field]))) throw new \UnexpectedValueException();
                $draft[$field] = trim($draft[$field]);
            }
            $usage = [];
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
                if (is_int($data['usage'][$field] ?? null) && $data['usage'][$field] >= 0) $usage[$field] = $data['usage'][$field];
            }
            return $draft + ['usage' => $usage];
        } catch (EmailPreparationException $error) {
            throw $error;
        } catch (\Throwable) {
            // Provider errors can contain headers or case data; never attach/log the original exception.
            throw new EmailPreparationException('provider_error', 'The API request or generated answer could not be completed. Preview the request and try again when ready.', 502);
        }
    }
}
