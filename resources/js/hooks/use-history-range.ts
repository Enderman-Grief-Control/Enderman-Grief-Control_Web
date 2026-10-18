import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { dashboard } from '@/routes';
import type { HistoryRangeKey } from '@/types';

export function useHistoryRange(historyRange: HistoryRangeKey) {
    const [pendingRange, setPendingRange] = useState<HistoryRangeKey | null>(
        null,
    );
    const [rangeError, setRangeError] = useState(false);
    const visitGeneration = useRef(0);
    const cancelVisit = useRef<(() => void) | null>(null);
    const range = pendingRange ?? historyRange;

    useEffect(
        () => () => {
            ++visitGeneration.current;
            cancelVisit.current?.();
        },
        [],
    );

    function changeRange(nextRange: HistoryRangeKey) {
        if (nextRange === range && pendingRange === null && !rangeError) return;
        const generation = ++visitGeneration.current;
        cancelVisit.current?.();
        setPendingRange(nextRange);
        setRangeError(false);
        const fail = () => {
            if (visitGeneration.current === generation) setRangeError(true);
            return false;
        };
        router.get(
            dashboard.url({ query: { historyRange: nextRange } }),
            {},
            {
                only: ['historyRange'],
                replace: true,
                preserveState: true,
                preserveScroll: true,
                onCancelToken: (token) => {
                    cancelVisit.current = () => token.cancel();
                },
                onError: fail,
                onHttpException: fail,
                onNetworkError: fail,
                onFinish: () => {
                    if (visitGeneration.current === generation) {
                        setPendingRange(null);
                        cancelVisit.current = null;
                    }
                },
            },
        );
    }

    return { range, rangeError, changeRange };
}
