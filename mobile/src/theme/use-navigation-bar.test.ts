/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import { Platform } from 'react-native';
import { useSystemNavigationBar } from './use-navigation-bar';

jest.mock('expo-navigation-bar', () => ({ setStyle: jest.fn() }));
import * as NavigationBar from 'expo-navigation-bar';
const setStyle = NavigationBar.setStyle as jest.Mock;

function renderWith(scheme: 'light' | 'dark') {
  function Probe() {
    useSystemNavigationBar(scheme);
    return null;
  }
  act(() => {
    TestRenderer.create(createElement(Probe));
  });
}

beforeEach(() => setStyle.mockClear());

describe('useSystemNavigationBar (#1222)', () => {
  it("sets a dark bar on Android in dark theme", () => {
    const os = jest.replaceProperty(Platform, 'OS', 'android');
    renderWith('dark');
    expect(setStyle).toHaveBeenCalledWith('dark');
    os.restore();
  });

  it("sets a light bar on Android in light theme", () => {
    const os = jest.replaceProperty(Platform, 'OS', 'android');
    renderWith('light');
    expect(setStyle).toHaveBeenCalledWith('light');
    os.restore();
  });

  it('is a no-op off Android', () => {
    const os = jest.replaceProperty(Platform, 'OS', 'ios');
    renderWith('dark');
    expect(setStyle).not.toHaveBeenCalled();
    os.restore();
  });
});
