import { useCallback, useState } from 'react';
import { api } from '../api/client';
import { LD_JSON } from '../api/config';

// Email-change-by-magic-link flow (backend #777). Two steps:
//   1. requestEmailChange(newEmail) -> POST /users/me/email-change (202): a
//      confirmation link is sent to the NEW address.
//   2. verifyEmailChange(token)     -> POST /users/me/email-change/verify (204):
//      consume the single-use token from that link and commit the new email.
// The user is resolved from the JWT server-side, so both calls require an
// authenticated session (the deep link opens the app while logged in).

export async function requestEmailChange(newEmail: string): Promise<boolean> {
  const { response } = await api.POST('/users/me/email-change', {
    body: { newEmail },
    headers: { 'Content-Type': LD_JSON, Accept: LD_JSON },
  });
  return response.ok;
}

// The verify operation shares the EmailChange schema (newEmail is required by the
// type), but only `token` is validated server-side in the verify group; newEmail
// is ignored, so we send it empty to satisfy the generated body type.
export async function verifyEmailChange(token: string): Promise<boolean> {
  const { response } = await api.POST('/users/me/email-change/verify', {
    body: { newEmail: '', token },
    headers: { 'Content-Type': LD_JSON, Accept: LD_JSON },
  });
  return response.ok;
}

export interface UseEmailChange {
  busy: boolean;
  sent: boolean;
  error: boolean;
  submit: (newEmail: string) => Promise<void>;
  reset: () => void;
}

// Drives the request screen: a single in-flight request with sent/error feedback.
export function useEmailChange(): UseEmailChange {
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState(false);

  const submit = useCallback(async (newEmail: string) => {
    setBusy(true);
    setError(false);
    try {
      if (await requestEmailChange(newEmail)) {
        setSent(true);
      } else {
        setError(true);
      }
    } catch {
      setError(true);
    } finally {
      setBusy(false);
    }
  }, []);

  const reset = useCallback(() => {
    setSent(false);
    setError(false);
  }, []);

  return { busy, sent, error, submit, reset };
}
