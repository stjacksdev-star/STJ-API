<?php

namespace App\Http\Controllers\Api;

use App\Services\FirebasePushService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushSendController extends BaseController
{
    public function __construct(
        private readonly FirebasePushService $push,
    ) {}

    public function __invoke(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'target_type' => ['required', 'string', Rule::in(['topic', 'platform'])],
            'platform' => ['required_if:target_type,platform', 'nullable', 'string', Rule::in(['Todo', 'Android', 'Ios'])],
            'topic' => ['required_if:target_type,topic', 'nullable', 'string', 'max:500'],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:500'],
            'data' => ['nullable', 'array'],
        ]);

        $data = (array) ($validated['data'] ?? []);

        if (! empty($validated['topic'])) {
            $data['topic'] = $validated['topic'];
        }

        if (($validated['target_type'] ?? '') === 'platform') {
            $result = $this->push->sendToPlatform(
                (string) $validated['platform'],
                (string) $validated['title'],
                (string) $validated['body'],
                $data,
            );

            return $this->success([
                'targetType' => 'platform',
                'platform' => $validated['platform'],
                'sent' => $result['sent'],
                'failed' => $result['failed'],
            ], 'Push enviada por plataforma');
        }

        $result = $this->push->sendToTopic(
            (string) $validated['topic'],
            (string) $validated['title'],
            (string) $validated['body'],
            $data,
        );

        return $this->success([
            'targetType' => 'topic',
            'topic' => $validated['topic'],
            'result' => $result,
        ], 'Push enviada por topic');
    }
}
