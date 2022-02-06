<?php

namespace LucasDotDev\Soulbscription\Models\Concerns;

use LucasDotDev\Soulbscription\Models\Feature;
use LucasDotDev\Soulbscription\Models\FeatureConsumption;
use LucasDotDev\Soulbscription\Models\Plan;
use LucasDotDev\Soulbscription\Models\Subscription;
use LucasDotDev\Soulbscription\Models\SubscriptionRenewal;
use OutOfBoundsException;
use OverflowException;

trait HasSubscriptions
{
    public function activePlans()
    {
        return $this->plans()
            ->wherePivot('expires_at', '>', now());
    }

    public function featureConsumptions()
    {
        return $this->morphMany(FeatureConsumption::class, 'subscriber');
    }

    public function plans()
    {
        return $this->belongsToMany(Plan::class, 'subscriptions', 'subscriber_id')
            ->as('subscription')
            ->withPivot(app(Subscription::class)->getFillable());
    }

    public function renewals()
    {
        return $this->hasManyThrough(SubscriptionRenewal::class, Subscription::class, 'subscriber_id');
    }

    public function subscriptions()
    {
        return $this->morphMany(Subscription::class, 'subscriber');
    }

    public function canConsume($featureName, ?float $consumption = null): bool
    {
        if (empty($feature = $this->getAvailableFeature($featureName))) {
            return false;
        }

        if (!$feature->consumable) {
            return true;
        }

        $currentConsumption = $this->featureConsumptions()
            ->whereBelongsTo($feature)
            ->unexpired()
            ->sum('consumption');

        return ($currentConsumption + $consumption) <= $feature->pivot->charges;
    }

    public function cantConsume($featureName, ?float $consumption = null): bool
    {
        return !$this->canConsume($featureName, $consumption);
    }

    public function hasFeature($featureName): bool
    {
        return !$this->missingFeature($featureName);
    }

    public function missingFeature($featureName): bool
    {
        return empty($this->getAvailableFeature($featureName));
    }

    /**
     * @throws \Illuminate\Auth\Access\OutOfBoundsException
     * @throws \Illuminate\Auth\Access\OverflowException
     */
    public function consume($featureName, ?float $consumption = null)
    {
        throw_if($this->missingFeature($featureName), new OutOfBoundsException(
            'None of the active plans grants access to this feature.',
        ));

        throw_if($this->cantConsume($featureName, $consumption), new OverflowException(
            'The feature has no enough charges to this consumption.',
        ));

        $consumedPlan = $this->activePlans->first(fn (Plan $plan) => $plan->features->firstWhere('name', $featureName));
        $feature      = $consumedPlan->features->firstWhere('name', $featureName);

        $consumptionExpiration = $feature->calculateExpiration($consumedPlan->subscription->created_at);

        $this->featureConsumptions()
            ->make([
                'consumption' => $consumption,
                'expires_at'  => $consumptionExpiration,
            ])
            ->feature()
            ->associate($feature)
            ->save();
    }

    public function subscribeTo(Plan $plan, $expiration = null): Subscription
    {
        $expiration = $expiration ?? $plan->calculateExpiration();

        return tap(
            $this->subscriptions()
                ->make([
                    'expires_at' => $expiration,
                ])
                ->plan()
                ->associate($plan),
        )->save();
    }

    private function getAvailableFeature(string $featureName): ?Feature
    {
        $this->loadMissing('activePlans.features');

        $availableFeatures = $this->activePlans->flatMap(fn (Plan $plan) => $plan->features);
        $feature           = $availableFeatures->firstWhere('name', $featureName);

        return $feature;
    }
}