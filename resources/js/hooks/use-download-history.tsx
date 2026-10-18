import { createContext, useContext, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { createHistoryRequestQueue } from '@/lib/history-request-queue';
import { login } from '@/routes';
import { history } from '@/routes/dashboard';
import type { HistoryRangeKey, HistoryResponse } from '@/types';

type HistoryState =
    | { key: string; status: 'ready'; data: HistoryResponse }
    | { key: string; status: 'loading' | 'error' };

const RequestQueue = createContext<ReturnType<
    typeof createHistoryRequestQueue
> | null>(null);

export function DownloadHistoryProvider({ children }: { children: ReactNode }) {
    const [run] = useState(createHistoryRequestQueue);
    return (
        <RequestQueue.Provider value={run}>{children}</RequestQueue.Provider>
    );
}

export function useDownloadHistory(
    range: HistoryRangeKey,
    anchor: string | null,
    identitySet: string,
    distributionId?: number,
    enabled = true,
) {
    const run = useContext(RequestQueue);
    if (run === null)
        throw new Error('Download history requires its dashboard provider');
    const cache = useRef(new Map<string, HistoryResponse>());
    const generation = useRef(0);
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState<HistoryState>({
        key: '',
        status: 'loading',
    });
    const key = JSON.stringify([
        distributionId ?? 'aggregate',
        range,
        anchor,
        identitySet,
    ]);

    useEffect(() => {
        cache.current.clear();
    }, [anchor, identitySet]);

    useEffect(() => {
        const requestGeneration = ++generation.current;
        const controller = new AbortController();
        if (anchor === null || !enabled) return;
        const cached = cache.current.get(key);
        if (cached) {
            setState({ key, status: 'ready', data: cached });
            return;
        }
        setState({ key, status: 'loading' });
        const current = () =>
            !controller.signal.aborted &&
            generation.current === requestGeneration;

        void run(async () => {
            const response = await fetch(
                history.url({
                    query: { range, anchor, distribution: distributionId },
                }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            );
            if (!current()) return;
            if (
                response.status === 401 ||
                response.status === 419 ||
                (response.redirected &&
                    new URL(response.url).pathname === login.url())
            ) {
                window.location.assign(login.url());
                return;
            }
            if (!response.ok) throw new Error('History request failed');
            const data: HistoryResponse = await response.json();
            if (!current()) return;
            if (
                data.range !== range ||
                data.anchorAt !== anchor ||
                (distributionId === undefined
                    ? data.scope !== 'aggregate'
                    : data.scope !== 'versions' ||
                      data.distributionId !== distributionId)
            ) {
                throw new Error('Unexpected history response');
            }
            cache.current.set(key, data);
            setState({ key, status: 'ready', data });
        }, controller.signal).catch(() => {
            if (current()) setState({ key, status: 'error' });
        });

        return () => controller.abort();
    }, [run, key, range, anchor, distributionId, enabled, attempt]);

    return {
        state: state.key === key ? state : { key, status: 'loading' as const },
        retry: () => setAttempt((value) => value + 1),
    };
}
