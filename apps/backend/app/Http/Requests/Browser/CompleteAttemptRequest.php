<?php

namespace App\Http\Requests\Browser;

use App\Support\Browser\CheckOutcomePolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CompleteAttemptRequest extends FormRequest
{
    public const STEP_KEYS = ['index', 'code', 'status', 'started_at', 'finished_at', 'assertions', 'network_summary', 'error_code'];

    public const ASSERTION_KEYS = ['code', 'passed', 'detail_code'];

    public const NETWORK_KEYS = ['method', 'origin', 'path', 'status', 'duration_ms', 'party', 'failure'];

    public const ERROR_KEYS = ['type', 'party', 'origin', 'path', 'status', 'step_code', 'message_code', 'count'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fencing_token' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:passed,failed,inconclusive,blocked,unsupported,cancelled'],
            'finished_at' => ['required', 'date'],
            'error_code' => ['nullable', 'string', 'in:'.implode(',', CheckOutcomePolicy::ERROR_CODES)],
            'steps' => ['present', 'array', 'max:20'],
            'steps.*' => ['array'],
            'steps.*.index' => ['required', 'integer', 'min:0', 'max:19', 'distinct'],
            'steps.*.code' => ['required', 'string', 'max:128', 'regex:/^[a-z][a-z0-9_]*$/'],
            'steps.*.status' => ['required', 'string', 'in:passed,failed,skipped,inconclusive,blocked'],
            'steps.*.started_at' => ['required', 'date'],
            'steps.*.finished_at' => ['required', 'date', 'after_or_equal:steps.*.started_at'],
            'steps.*.assertions' => ['present', 'array', 'max:20'],
            'steps.*.assertions.*' => ['array'],
            'steps.*.network_summary' => ['sometimes', 'array', 'max:50'],
            'steps.*.network_summary.*' => ['array'],
            'steps.*.error_code' => ['nullable', 'string', 'in:'.implode(',', CheckOutcomePolicy::ERROR_CODES)],
            'diagnostics' => ['required', 'array'],
            'diagnostics.redaction_version' => ['required', 'string', 'max:32'],
            'diagnostics.relevant_errors' => ['present', 'array', 'max:100'],
            'diagnostics.relevant_errors.*' => ['array'],
            'artifact_ids' => ['sometimes', 'array', 'max:10'],
            'artifact_ids.*' => ['uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->all();

            if (array_diff(array_keys($data), ['fencing_token', 'status', 'finished_at', 'error_code', 'steps', 'diagnostics', 'artifact_ids']) !== []) {
                $validator->errors()->add('body', 'unknown_field');
            }

            foreach ((array) ($data['steps'] ?? []) as $i => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $this->checkKeys($validator, "steps.$i", $step, self::STEP_KEYS);

                foreach ((array) ($step['assertions'] ?? []) as $j => $assertion) {
                    $this->checkFlat($validator, "steps.$i.assertions.$j", $assertion, self::ASSERTION_KEYS);
                }

                foreach ((array) ($step['network_summary'] ?? []) as $j => $request) {
                    $this->checkFlat($validator, "steps.$i.network_summary.$j", $request, self::NETWORK_KEYS);
                }
            }

            $diagnostics = (array) ($data['diagnostics'] ?? []);
            $this->checkKeys($validator, 'diagnostics', $diagnostics, ['redaction_version', 'relevant_errors']);

            foreach ((array) ($diagnostics['relevant_errors'] ?? []) as $j => $error) {
                $this->checkFlat($validator, "diagnostics.relevant_errors.$j", $error, self::ERROR_KEYS);
            }
        });
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $allowed
     */
    private function checkKeys(Validator $validator, string $path, array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            $validator->errors()->add($path, 'unknown_field');
        }
    }

    /**
     * Sanitized metadata only: whitelisted keys, scalar values, short strings, and paths without
     * query strings or fragments (they may carry tokens or e-mail addresses).
     *
     * @param  list<string>  $allowed
     */
    private function checkFlat(Validator $validator, string $path, mixed $value, array $allowed): void
    {
        if (! is_array($value)) {
            $validator->errors()->add($path, 'not_an_object');

            return;
        }

        $this->checkKeys($validator, $path, $value, $allowed);

        foreach ($value as $key => $item) {
            if (! is_scalar($item) && $item !== null) {
                $validator->errors()->add("$path.$key", 'not_scalar');
            } elseif (is_string($item) && mb_strlen($item) > 512) {
                $validator->errors()->add("$path.$key", 'too_long');
            } elseif ($key === 'path' && is_string($item) && preg_match('/[?#@]/', $item) === 1) {
                $validator->errors()->add("$path.$key", 'path_not_redacted');
            }
        }
    }
}
