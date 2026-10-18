import { useEffect, useRef, useState } from 'react';
import { login } from '@/routes';
import { history } from '@/routes/dashboard';
import type { AggregateHistory, HistoryRangeKey } from '@/types';

type HistoryState =
    | { key: string; status: 'ready'; data: AggregateHistory }
    | { key: string; status: 'loading' | 'error' };

export function useAggregateHistory(
    range: HistoryRangeKey,
    anchor: string | null,
    identitySet: string,
) {
    const cache = useRef(new Map<string, AggregateHistory>());
    const generation = useRef(0);
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState<HistoryState>({
        key: '',
        status: 'loading',
    });
    const key = JSON.stringify(['aggregate', range, anchor, identitySet]);

    useEffect(() => {
        cache.current.clear();
    }, [anchor, identitySet]);

    useEffect(() => {
        const requestGeneration = ++generation.current;
        const controller = new AbortController();
        const cached = cache.current.get(key);

        if (anchor === null) {
            return;
        }

        if (cached) {
            setState({ key, status: 'ready', data: cached });
            return;
        }

        setState({ key, status: 'loading' });

        void (async () => {
            try {
                const response = await fetch(
                    history.url({ query: { range, anchor } }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    },
                );

                if (
                    controller.signal.aborted ||
                    generation.current !== requestGeneration
                ) {
                    return;
                }

                if (
                    response.status === 401 ||
                    response.status === 419 ||
                    (response.redirected &&
                        new URL(response.url).pathname === login.url())
                ) {
                    window.location.assign(login.url());
                    return;
                }

                if (!response.ok) {
                    throw new Error('History request failed');
                }

                const data: AggregateHistory = await response.json();

                if (
                    controller.signal.aborted ||
                    generation.current !== requestGeneration
                ) {
                    return;
                }

                if (
                    data.scope !== 'aggregate' ||
                    data.range !== range ||
                    data.anchorAt !== anchor
                ) {
                    throw new Error('Unexpected history response');
                }

                cache.current.set(key, data);
                setState({ key, status: 'ready', data });
            } catch {
                if (
                    !controller.signal.aborted &&
                    generation.current === requestGeneration
                ) {
                    setState({ key, status: 'error' });
                }
            }
        })();

        return () => controller.abort();
    }, [key, range, anchor, attempt]);

    return {
        // Do not render a previous range or data generation while the effect starts.
        state: state.key === key ? state : { key, status: 'loading' as const },
        retry: () => setAttempt((value) => value + 1),
    };
}
