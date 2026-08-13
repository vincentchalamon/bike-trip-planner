import type { fr } from './fr';

// English resources — must mirror the fr key set (i18n.test.ts enforces it).
export const en: typeof fr = {
  common: {
    retry: 'Retry',
  },
  nav: {
    create: 'Create',
    trips: 'Trips',
    account: 'Account',
  },
  header: {
    login: 'Sign in',
    trip: 'Roadbook',
  },
  login: {
    brand: 'Bike Trip Planner',
    emailLabel: 'Email address',
    emailPlaceholder: 'you@example.com',
    submit: 'Send me a link',
    submitting: 'Sending…',
    sent: 'A sign-in link was sent to {{email}}. Open it on this device to sign in.',
    error: 'Could not send the link. Check the address and try again.',
  },
  auth: {
    verifying: 'Signing in…',
  },
  trips: {
    title: 'My trips',
    error: 'Could not load trips.',
    empty: 'No trips yet.',
    untitled: 'Untitled trip',
    meta: '{{stages}} stages · {{distance}} km · {{status}}',
  },
  create: {
    title: 'Create a trip',
    description:
      'Paste a Komoot, Strava or RideWithGPS link to generate your roadbook. Coming soon.',
  },
  account: {
    title: 'Account',
    language: 'Language',
    logout: 'Sign out',
  },
  trip: {
    title: 'Roadbook',
    segmentRoadbook: 'Roadbook',
    segmentMap: 'Map',
    empty: 'No stage computed.',
    mapEmpty: 'No route available for this trip.',
    day: 'Day {{day}}',
    rest: 'rest',
    stageMeta: '{{distance}} km · +{{elevation}} m',
    delete: 'Delete',
    deleteA11y: 'Delete day {{day}}',
    deleteConfirmTitle: 'Delete this stage?',
    deleteConfirmMessage: 'The day will be merged with the neighbouring stage.',
    cancel: 'Cancel',
    lockedTitle: 'Trip started',
    lockedMessage: 'This trip has started: it is read-only.',
    deleteFailedTitle: 'Deletion failed',
    deleteFailedMessage: 'The deletion failed. Try again.',
  },
  language: {
    fr: 'Français',
    en: 'English',
  },
};
