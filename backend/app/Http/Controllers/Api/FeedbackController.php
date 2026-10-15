<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class FeedbackController extends Controller
{
    private const CATEGORIES = [
        'bug' => '問題回報',
        'data' => '資料錯誤',
        'suggestion' => '功能建議',
        'other' => '其他',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:bug,data,suggestion,other'],
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $user = User::query()->findOrFail((int) $request->attributes->get('auth_user_id'));
        $category = self::CATEGORIES[$validated['category']];
        $submittedAt = now();
        $data = [
            'category' => $category,
            'subject' => $validated['subject'],
            'reporterName' => $user->display_name,
            'reporterEmail' => $user->email,
            'userId' => $user->id,
            'submittedAt' => $submittedAt->toDateTimeString(),
            'message' => $validated['message'],
            'year' => $submittedAt->year,
        ];

        try {
            Mail::send([
                'html' => 'emails.feedback-report',
                'text' => 'emails.feedback-report-text',
            ], $data, function (Message $mail) use ($user): void {
                $mail->to((string) config('services.feedback.email'))
                    ->subject('[動漫庫] 問題回報')
                    ->replyTo($user->email, $user->display_name ?: $user->email);
            });
        } catch (Throwable $exception) {
            report($exception);

            throw new ApiException(503, 'feedback_unavailable', '暫時無法寄出回報，請稍後再試');
        }

        return response()->json(['ok' => true]);
    }
}
