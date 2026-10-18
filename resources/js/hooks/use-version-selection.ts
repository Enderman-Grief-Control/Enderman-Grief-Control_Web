import { useState } from 'react';
import type { DashboardVersion } from '@/types';

export function useVersionSelection(versions: DashboardVersion[]) {
    const ids = versions.map((version) => version.id);
    const identitySet = JSON.stringify([...ids].sort((a, b) => a - b));
    const [selection, setSelection] = useState({
        identitySet,
        hidden: new Set<number>(),
    });
    // Reconcile during render so a removal is forgotten before a later restoration.
    const hidden =
        selection.identitySet === identitySet
            ? selection.hidden
            : new Set([...selection.hidden].filter((id) => ids.includes(id)));
    if (selection.identitySet !== identitySet)
        setSelection({ identitySet, hidden });

    return {
        selected: new Set(ids.filter((id) => !hidden.has(id))),
        toggle(id: number) {
            const next = new Set(hidden);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            setSelection({ identitySet, hidden: next });
        },
        selectAll: () => setSelection({ identitySet, hidden: new Set() }),
        clear: () => setSelection({ identitySet, hidden: new Set(ids) }),
    };
}
