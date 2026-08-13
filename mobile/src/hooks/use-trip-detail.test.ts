/// <reference types="jest" />
import { runLoadTripDetail } from './use-trip-detail';

jest.mock('../api/trips', () => ({ fetchTripDetail: jest.fn() }));
import { fetchTripDetail } from '../api/trips';
const mockDetail = fetchTripDetail as jest.MockedFunction<typeof fetchTripDetail>;

beforeEach(() => jest.clearAllMocks());

describe('runLoadTripDetail (#1031)', () => {
  it('returns the detail on success', async () => {
    mockDetail.mockResolvedValue({ title: 'Trip' } as never);
    const { detail, error } = await runLoadTripDetail('t1');
    expect(detail).toEqual({ title: 'Trip' });
    expect(error).toBeNull();
  });

  it('reports "Voyage introuvable." when the detail is null', async () => {
    mockDetail.mockResolvedValue(null);
    const { detail, error } = await runLoadTripDetail('t1');
    expect(detail).toBeNull();
    expect(error).toBe('Voyage introuvable.');
  });

  it('reports a load error when the fetch throws', async () => {
    mockDetail.mockRejectedValue(new Error('boom'));
    const { error } = await runLoadTripDetail('t1');
    expect(error).toBe('Impossible de charger le voyage.');
  });
});
