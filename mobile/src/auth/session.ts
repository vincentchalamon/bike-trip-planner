// Out-of-band session-invalidation channel. The API middleware runs outside React,
// so when a token refresh definitively fails it cannot flip AuthProvider's state
// directly. It calls notifySessionInvalidated(); AuthProvider subscribes and
// redirects to /login instead of leaving the user on screens that 401 forever.
type Listener = () => void;

const listeners = new Set<Listener>();

export function onSessionInvalidated(listener: Listener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function notifySessionInvalidated(): void {
  for (const listener of listeners) {
    listener();
  }
}
