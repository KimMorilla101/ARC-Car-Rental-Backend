<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Enums\RequirementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\ListBookingsRequest;
use App\Http\Requests\Bookings\QuoteBookingRequest;
use App\Http\Requests\Bookings\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Support\BookingPricing;
use App\Support\BusinessSettings;
use App\Support\UnitAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The renter's own bookings. Prices, availability, the unit, status and owner are always decided
 * here, never taken from the request.
 */
class BookingController extends Controller
{
    public function index(ListBookingsRequest $request): AnonymousResourceCollection
    {
        $bookings = Booking::query()
            ->withDetails()
            ->whereBelongsTo($request->user())
            ->when($request->validated('status'), fn ($query, string $filter) => $query->whereIn('status', BookingStatus::forFilter($filter)))
            ->latest()
            ->latest('id')
            ->get();

        return BookingResource::collection($bookings);
    }

    /**
     * Another renter's booking answers 404, exactly like a booking that does not exist.
     */
    public function show(Request $request, int $booking): BookingResource
    {
        return new BookingResource(
            Booking::query()->withDetails()->whereBelongsTo($request->user())->findOrFail($booking),
        );
    }

    /**
     * Price and availability for a window. Nothing is saved.
     */
    public function quote(QuoteBookingRequest $request, BookingPricing $pricing, UnitAvailability $availability, BusinessSettings $settings): JsonResponse
    {
        $vehicle = $request->vehicle();

        return response()->json([
            'data' => [
                'rental_days' => $request->rentalDays(),
                'available' => $availability->countFreeUnits($vehicle, $request->pickup(), $request->returnAt()) > 0,
                'pricing' => $pricing->price($vehicle, $request->rentalDays(), $request->deliveryMethod(), $request->zone()),
                'requirements' => $this->requirementList($settings->downPayment()),
            ],
        ]);
    }

    /**
     * Creates the booking and reserves one physical unit in a single transaction. The vehicle row
     * is locked first, so simultaneous requests for the same vehicle run one after the other and
     * the overlap check always sees the previous reservation.
     *
     * @throws ValidationException
     */
    public function store(StoreBookingRequest $request, BookingPricing $pricing, UnitAvailability $availability): JsonResponse
    {
        $paymentOption = $request->paymentOption();

        $booking = DB::transaction(function () use ($request, $pricing, $availability, $paymentOption) {
            $vehicle = Vehicle::query()->visibleToRenters()->whereKey($request->vehicle()->id)->lockForUpdate()->first();

            if ($vehicle === null) {
                throw ValidationException::withMessages(['vehicle_id' => 'This car is not available for booking.']);
            }

            $duplicate = Booking::query()
                ->whereBelongsTo($request->user())
                ->where('vehicle_id', $vehicle->id)
                ->where('pickup_at', $request->pickup())
                ->where('return_at', $request->returnAt())
                ->holdingUnit()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['vehicle_id' => 'You already have a booking for this car at these times.']);
            }

            $price = $pricing->price($vehicle, $request->rentalDays(), $request->deliveryMethod(), $request->zone());
            $unit = $availability->findFreeUnit($vehicle, $request->pickup(), $request->returnAt(), lock: true);

            if ($unit === null) {
                throw ValidationException::withMessages(['vehicle_id' => 'No unit of this car is free for those dates. Try different dates.']);
            }

            $delivery = $request->zone() !== null;

            $booking = Booking::create([
                'user_id' => $request->user()->id,
                'vehicle_id' => $vehicle->id,
                'vehicle_unit_id' => $unit->id,
                'pickup_at' => $request->pickup(),
                'return_at' => $request->returnAt(),
                'rental_days' => $request->rentalDays(),
                'delivery_method' => $request->deliveryMethod(),
                'branch_id' => $request->branch()?->id,
                'delivery_zone_id' => $request->zone()?->id,
                'delivery_address' => $delivery ? $request->validated('delivery_address') : null,
                'destination' => $request->validated('destination'),
                'payment_method' => $paymentOption->method,
                'payment_requires_proof' => $paymentOption->requires_proof,
                'daily_rate' => $vehicle->daily_rate,
                'monthly_rate' => $vehicle->monthly_rate,
                'rental_fee' => $price['rental_fee'],
                'delivery_fee' => $price['delivery_fee'],
                'car_wash_fee' => $price['car_wash_fee'],
                'down_payment' => $price['down_payment'],
                'total' => $price['total'],
                'agreement_version' => $request->validated('agreement_version'),
                'agreement_accepted_at' => now(),
            ]);

            $booking->forceFill(['reference' => sprintf('BK-%s-%04d', now()->setTimezone(config('app.api_timezone'))->format('Y'), $booking->id)])->save();

            foreach (RequirementType::cases() as $type) {
                $booking->requirements()->create(['type' => $type]);
            }

            return $booking;
        }, attempts: 3);

        return (new BookingResource(Booking::query()->withDetails()->findOrFail($booking->id)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @return array<int, array{type: string, label: string, description: string}>
     */
    private function requirementList(float $downPayment): array
    {
        return array_map(fn (RequirementType $type) => [
            'type' => $type->value,
            'label' => $type->label($downPayment),
            'description' => $type->description(),
        ], RequirementType::cases());
    }
}
