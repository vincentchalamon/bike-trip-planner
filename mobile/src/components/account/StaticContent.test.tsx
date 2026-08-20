/// <reference types="jest" />
import { withContactEmail } from './StaticContent';
import { CONTACT_EMAIL } from '../../api/config';

describe('withContactEmail (#1119 review fix)', () => {
  it('substitutes the __CONTACT_EMAIL__ token with CONTACT_EMAIL', () => {
    expect(withContactEmail('Contact us at: __CONTACT_EMAIL__.')).toBe(
      `Contact us at: ${CONTACT_EMAIL}.`,
    );
  });

  it('substitutes every occurrence of the token', () => {
    const body = '__CONTACT_EMAIL__ first, __CONTACT_EMAIL__ again.';
    expect(withContactEmail(body)).toBe(`${CONTACT_EMAIL} first, ${CONTACT_EMAIL} again.`);
  });

  it('leaves text without the token unchanged', () => {
    expect(withContactEmail('No token here.')).toBe('No token here.');
  });
});
