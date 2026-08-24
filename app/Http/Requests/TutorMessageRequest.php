<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TutorMessageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('message'))) {
            $normalized['message'] = trim($this->input('message'));
        }

        if (is_string($this->input('request_id'))) {
            $normalized['request_id'] = strtolower($this->input('request_id'));
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return $this->user()?->isStudent() ?? false;
    }

    public function rules(): array
    {
        $minimum = $this->messageMinimum();
        $maximum = max($minimum, (int) config('smart_tutor.input.max_characters', 4000));

        return [
            'message' => ['bail', 'required', 'string', 'min:'.$minimum, 'max:'.$maximum],
            'request_id' => ['bail', 'required', 'uuid'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = ['_token', '_method', 'message', 'request_id'];
                $unexpected = array_diff(array_keys($this->all()), $allowed);

                if ($unexpected !== []) {
                    $validator->errors()->add('payload', 'يحتوي الطلب على حقول غير مسموح بها.');
                }

                $message = $this->input('message');

                if (! is_string($message) || ! mb_check_encoding($message, 'UTF-8')) {
                    if (is_string($message)) {
                        $validator->errors()->add('message', 'يحتوي السؤال على ترميز غير صالح.');
                    }

                    return;
                }

                $visibleMessage = preg_replace('/[\pZ\pC]+/u', '', $message);

                if (
                    $visibleMessage === null
                    || $visibleMessage === ''
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $message) === 1
                ) {
                    $validator->errors()->add('message', 'يحتوي السؤال على محارف غير صالحة.');

                    return;
                }

                if (
                    ! $validator->errors()->has('message')
                    && mb_strlen($visibleMessage) < $this->messageMinimum()
                ) {
                    $validator->errors()->add('message', 'السؤال قصير جدًا.');
                }
            },
        ];
    }

    private function messageMinimum(): int
    {
        return max(1, (int) config('smart_tutor.input.min_characters', 2));
    }

    public function messages(): array
    {
        return [
            'message.required' => 'اكتب سؤالك أولًا.',
            'message.string' => 'يجب أن يكون السؤال نصًا.',
            'message.min' => 'السؤال قصير جدًا.',
            'message.max' => 'السؤال طويل جدًا.',
            'request_id.required' => 'معرّف الطلب مطلوب.',
            'request_id.uuid' => 'معرّف الطلب غير صالح.',
        ];
    }
}
