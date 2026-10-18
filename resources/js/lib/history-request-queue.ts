// One queue per dashboard keeps aggregate and expanded-panel reads within two slots.
export function createHistoryRequestQueue() {
    let active = 0;
    const waiting: (() => void)[] = [];

    function drain() {
        while (active < 2 && waiting.length > 0) waiting.shift()?.();
    }

    return function run<T>(
        operation: () => Promise<T>,
        signal: AbortSignal,
    ): Promise<T> {
        return new Promise((resolve, reject) => {
            function abort() {
                const index = waiting.indexOf(start);
                if (index !== -1) waiting.splice(index, 1);
                reject(
                    new DOMException('History request aborted', 'AbortError'),
                );
            }

            function start() {
                signal.removeEventListener('abort', abort);
                if (signal.aborted) {
                    abort();
                    return;
                }
                active++;
                // Keep the slot until an aborted fetch actually settles.
                void Promise.resolve()
                    .then(() => {
                        signal.throwIfAborted();
                        return operation();
                    })
                    .then(resolve, reject)
                    .finally(() => {
                        active--;
                        drain();
                    });
            }

            if (signal.aborted) {
                abort();
                return;
            }
            signal.addEventListener('abort', abort, { once: true });
            waiting.push(start);
            drain();
        });
    };
}
