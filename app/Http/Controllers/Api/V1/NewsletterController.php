<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NewsletterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConfirmNewsletterRequest;
use App\Http\Requests\Api\SubscribeNewsletterRequest;
use App\Http\Requests\Api\UnsubscribeNewsletterRequest;
use App\Mail\ConfirmNewsletterMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class NewsletterController extends Controller
{
    private const RESEND_AFTER_MINUTES = 5;

    public function subscribe(SubscribeNewsletterRequest $request): JsonResponse
    {
        $email = NewsletterSubscriber::normalizeEmail($request->validated()['email']);
        $subscriber = NewsletterSubscriber::query()->createOrFirst(['email' => $email]);

        $recentlySent = $subscriber->status === NewsletterStatus::Pending
            && $subscriber->last_confirmation_sent_at !== null
            && $subscriber->last_confirmation_sent_at->gt(now()->subMinutes(self::RESEND_AFTER_MINUTES));

        if ($subscriber->status !== NewsletterStatus::Confirmed && ! $recentlySent) {
            $token = $subscriber->requestConfirmation();

            Mail::to($subscriber->email)->queue(new ConfirmNewsletterMail($this->confirmUrl($token)));
        }

        // The same answer for every case, so the form cannot be used to find out who is subscribed.
        return $this->message('If this address can receive our newsletter, a confirmation email is on its way. Please check your inbox and spam folder.', 202);
    }

    public function confirm(ConfirmNewsletterRequest $request): JsonResponse
    {
        $subscriber = NewsletterSubscriber::findByConfirmToken($request->validated()['token']);

        if ($subscriber === null || ! $subscriber->confirm()) {
            throw ValidationException::withMessages([
                'token' => 'This confirmation link is invalid or has expired. Please sign up again.',
            ]);
        }

        return $this->message('You are subscribed. Thank you!');
    }

    public function unsubscribe(UnsubscribeNewsletterRequest $request): JsonResponse
    {
        $subscriber = NewsletterSubscriber::findByUnsubscribeToken($request->validated()['token']);

        abort_if($subscriber === null, 404);

        $subscriber->unsubscribe();

        return $this->message('You have been unsubscribed.');
    }

    private function confirmUrl(string $token): string
    {
        $base = rtrim((string) config('azhayon.frontend_url'), '/');

        return $base.'/newsletter/confirm?token='.rawurlencode($token);
    }

    private function message(string $text, int $status = 200): JsonResponse
    {
        return response()
            ->json(['data' => ['message' => $text]], $status)
            ->header('Cache-Control', 'no-store');
    }
}