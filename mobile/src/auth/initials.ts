// Avatar initials from an email: the first letters of up to the first two tokens
// of the local part (split on . _ -), uppercased. Falls back to '?' when empty.
export function initialsFromEmail(email: string | null | undefined): string {
  const local = (email ?? '').split('@')[0] ?? '';
  const tokens = local.split(/[._-]+/).filter(Boolean);
  const initials = tokens
    .slice(0, 2)
    .map((token) => token[0]!.toUpperCase())
    .join('');
  return initials || '?';
}
