<?php

namespace App\Http\Controllers;

use App\Analytics\ReadDownloadHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class DownloadHistoryController extends Controller
{
    public function __invoke(Request $request, ReadDownloadHistory $history): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'range' => ['bail', 'sometimes', 'required', 'string', 'in:7d,30d,90d,all'],
            'distribution' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
            'anchor' => [
                'bail', 'sometimes', 'required', 'string',
                'regex:/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?Z\z/',
                'date',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('The given data was invalid.'),
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var array{range?: string, distribution?: string, anchor?: string} $input */
        $input = $validator->validated();

        try {
            $response = $history(
                $input['range'] ?? '30d',
                isset($input['distribution']) ? (int) $input['distribution'] : null,
                isset($input['anchor']) ? CarbonImmutable::parse($input['anchor'], 'UTC') : null,
            );
        } catch (InvalidArgumentException $exception) {
            // The reader validates that a pinned anchor is a stored active capture.
            return response()->json([
                'message' => __('The given data was invalid.'),
                'errors' => ['anchor' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json($response);
    }
}
