<?php

namespace App\Http\Requests\Tracking;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTrackingPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('tracked_at')) {
            try {
                $this->merge([
                    'tracked_at' => CarbonImmutable::parse($this->input('tracked_at'))->utc()->format('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable) {
                // Let the date rule report the validation error.
            }
        }
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'altitude' => ['nullable', 'numeric'],
            'battery' => ['nullable', 'integer', 'min:0', 'max:100'],
            'provider' => ['nullable', 'string', 'max:20'],
            'tracked_at' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('tracked_at')) {
                return;
            }

            try {
                $trackedAt = CarbonImmutable::parse($this->input('tracked_at'));
            } catch (\Throwable) {
                return;
            }

            $maxAgeHours = max(1, (int) config('tracking.point_max_age_hours', 48));
            $maxFutureMinutes = max(0, (int) config('tracking.point_max_future_minutes', 5));
            $now = CarbonImmutable::now('UTC');

            if ($trackedAt->lt($now->subHours($maxAgeHours))) {
                $validator->errors()->add(
                    'tracked_at',
                    "The tracking point cannot be older than {$maxAgeHours} hours."
                );
            }

            if ($trackedAt->gt($now->addMinutes($maxFutureMinutes))) {
                $validator->errors()->add(
                    'tracked_at',
                    "The tracking point cannot be more than {$maxFutureMinutes} minutes in the future."
                );
            }
        });
    }
}
