// Design tokens & theme for all Bike Trip Planner screens
// Outdoor, warm, map-inspired palette. Dark mode via `dark` flag.

const TOKENS = {
  // Font stacks — pin real fonts (Google Fonts loaded in HTML)
  serif: '"Fraunces", "Cormorant Garamond", Georgia, serif',
  sans:  '"Inter Tight", "Inter", system-ui, -apple-system, sans-serif',
  mono:  '"JetBrains Mono", ui-monospace, monospace',
};

function makeTheme(accent, dark) {
  // Accent families
  const accents = {
    amber:  { solid: '#c2671e', soft: '#fbe6cc', softer: '#fdf2e0', ink: '#6b3a14' },
    forest: { solid: '#4a6b3a', soft: '#d9e3cc', softer: '#eaf0de', ink: '#2a3e1f' },
    indigo: { solid: '#3b5998', soft: '#d3dbef', softer: '#e8ecf7', ink: '#1f2e52' },
    brick:  { solid: '#a8412a', soft: '#f0d0c6', softer: '#f8e5dd', ink: '#5c1f12' },
  };
  const a = accents[accent] || accents.amber;

  if (dark) return {
    ...TOKENS,
    name: 'dark',
    bg:         '#1a1814',
    surface:    '#24211c',
    surfaceAlt: '#2c2822',
    line:       '#3a352d',
    lineSoft:   '#2f2a23',
    ink:        '#f5ede0',
    inkSoft:    '#c9bfad',
    inkMute:    '#8a8173',
    accent:     a.solid,
    accentSoft: '#3a2d1d',
    accentInk:  '#f1c999',
    red:        '#e57a5a',    redSoft: '#3a241d',
    blue:       '#7ba3c9',    blueSoft: '#1d2b3a',
    green:      '#8fb170',    greenSoft: '#1f2a1a',
    forest:     '#a8c07e',
    rose:       '#d8967a',
    shadow:     '0 1px 2px rgba(0,0,0,0.4), 0 4px 12px rgba(0,0,0,0.25)',
    shadowSoft: '0 1px 2px rgba(0,0,0,0.3)',
    paper:      'radial-gradient(ellipse at top, #2a2620 0%, #1a1814 80%)',
  };

  return {
    ...TOKENS,
    name: 'light',
    bg:         '#faf7f0',       // warm paper
    surface:    '#ffffff',
    surfaceAlt: '#f3ece0',
    line:       '#e4dbc9',
    lineSoft:   '#efe7d5',
    ink:        '#2a2418',
    inkSoft:    '#5a4e3a',
    inkMute:    '#8b7e66',
    accent:     a.solid,
    accentSoft: a.soft,
    accentSofter: a.softer,
    accentInk:  a.ink,
    red:        '#b84420',   redSoft:   '#f5ddd0',
    blue:       '#3d6b91',   blueSoft:  '#d8e4ef',
    green:      '#4a7a3a',   greenSoft: '#dbe8ce',
    forest:     '#4a6b3a',
    rose:       '#b86a3e',
    shadow:     '0 1px 2px rgba(80,60,30,0.06), 0 8px 24px rgba(80,60,30,0.08)',
    shadowSoft: '0 1px 2px rgba(80,60,30,0.04)',
    paper:      'radial-gradient(ellipse at top, #fdf9ef 0%, #f5ecda 100%)',
  };
}

Object.assign(window, { TOKENS, makeTheme });
