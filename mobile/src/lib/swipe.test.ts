/// <reference types="jest" />
import { swipeToView } from './swipe';

describe('swipeToView', () => {
  it('selects the map on a decisive left swipe', () => {
    expect(swipeToView(-80)).toBe('map');
  });

  it('selects the roadbook on a decisive right swipe', () => {
    expect(swipeToView(80)).toBe('roadbook');
  });

  it('does nothing below the threshold', () => {
    expect(swipeToView(-10)).toBeNull();
    expect(swipeToView(10)).toBeNull();
    expect(swipeToView(0)).toBeNull();
  });

  it('honours a custom threshold', () => {
    expect(swipeToView(30, 20)).toBe('roadbook');
    expect(swipeToView(30, 60)).toBeNull();
  });
});
