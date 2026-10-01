// react-test-renderer has no automatic cleanup: a tree a test forgets to unmount
// stays subscribed to the stores and to i18n, re-renders outside act() when a
// later test mutates them, and keeps its timers (VirtualizedList batching) alive
// past the end of the suite ("Cannot log after tests are done"). Track every
// renderer and unmount the leftovers after each test, as Testing Library does.
const TestRenderer = require('react-test-renderer');

const mounted = new Set();
const create = TestRenderer.create;
TestRenderer.create = (...args) => {
  const renderer = create(...args);
  mounted.add(renderer);
  return renderer;
};

afterEach(() => {
  TestRenderer.act(() => {
    mounted.forEach((renderer) => renderer.unmount());
  });
  mounted.clear();
});
