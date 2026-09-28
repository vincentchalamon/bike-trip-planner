import { clearAllTripCache } from './trip-cache';
import { clearCachedTripList } from './trips-list-cache';

// Purge every account-scoped file kept for offline use, so an ended session leaves
// no roadbook, manual accommodation or trip title on a shared device (#1174).
// Shared by logout and by a definitively failed refresh so both wipe the same data.
export async function clearLocalAccountData(): Promise<void> {
  await clearAllTripCache();
  await clearCachedTripList();
}
