import { describe, expect, it } from 'vitest';
import {
  apiFieldErrors,
  bodyOf,
  colourDigits,
  colourValue,
  draftOf,
  EMPTY_DRAFT,
  PARTY_COLOURS,
  swatchText,
  validateParty,
  type PartyDraft,
} from './partyForm';

const draft = (changes: Partial<PartyDraft>): PartyDraft => ({
  ...EMPTY_DRAFT,
  name: 'Avenir Étudiant',
  ...changes,
});

describe('the colour choices', () => {
  it('are the eight of the design, the first being the default', () => {
    expect(PARTY_COLOURS).toEqual([
      '5468D4',
      'C2410C',
      '0F766E',
      '7C5BD0',
      'A8349A',
      '8A5A00',
      '1E2A5A',
      '9A2A0A',
    ]);
    expect(EMPTY_DRAFT.colour).toBe('5468D4');
  });

  it('convert between the digits and the #RRGGBB value, whatever the case', () => {
    expect(colourDigits('#c2410c')).toBe('C2410C');
    expect(colourDigits('C2410C')).toBe('C2410C');
    expect(colourValue('c2410c')).toBe('#C2410C');
  });
});

describe('validateParty', () => {
  it('accepts a name with the default colour', () => {
    expect(validateParty(draft({}))).toEqual({});
  });

  it('wants a name that is not only spaces', () => {
    expect(validateParty(draft({ name: '' })).name).toBe('required');
    expect(validateParty(draft({ name: '   ' })).name).toBe('required');
  });

  it('refuses a name of more than 100 characters, counted after trimming', () => {
    expect(validateParty(draft({ name: 'a'.repeat(100) })).name).toBeUndefined();
    expect(validateParty(draft({ name: ` ${'a'.repeat(100)} ` })).name).toBeUndefined();
    expect(validateParty(draft({ name: 'a'.repeat(101) })).name).toBe('max');
  });

  it('refuses an acronym of more than 15 characters and accepts a blank one', () => {
    expect(validateParty(draft({ acronym: '' })).acronym).toBeUndefined();
    expect(validateParty(draft({ acronym: 'a'.repeat(15) })).acronym).toBeUndefined();
    expect(validateParty(draft({ acronym: 'a'.repeat(16) })).acronym).toBe('max');
  });

  it('refuses a colour that is not six hexadecimal digits', () => {
    expect(validateParty(draft({ colour: 'c2410c' })).colour).toBeUndefined();
    expect(validateParty(draft({ colour: 'red' })).colour).toBe('hex_colour');
    expect(validateParty(draft({ colour: '' })).colour).toBe('hex_colour');
  });
});

describe('bodyOf', () => {
  it('trims, sends a blank acronym as null and the colour as #RRGGBB', () => {
    expect(bodyOf(draft({ name: '  Ensemble ', acronym: '  ', colour: 'c2410c' }))).toEqual({
      name: 'Ensemble',
      acronym: null,
      colour: '#C2410C',
    });
    expect(bodyOf(draft({ acronym: ' AE ' })).acronym).toBe('AE');
  });
});

describe('draftOf', () => {
  it('fills the form from a party, a missing acronym being an empty field', () => {
    expect(draftOf({ name: 'Ensemble', acronym: null, colour: '#C2410C' })).toEqual({
      name: 'Ensemble',
      acronym: '',
      colour: 'C2410C',
    });
  });
});

describe('apiFieldErrors', () => {
  it('keeps the first code of the fields of the form only', () => {
    expect(
      apiFieldErrors({ name: ['unique', 'max'], logo: ['invalid'], colour: ['hex_colour'] }),
    ).toEqual({ name: 'unique', colour: 'hex_colour' });
  });
});

describe('swatchText', () => {
  it('is the acronym when there is one', () => {
    expect(swatchText({ name: 'Avenir Étudiant', acronym: 'AE' })).toBe('AE');
    expect(swatchText({ name: 'Avenir Étudiant', acronym: ' ae ' })).toBe('ae');
  });

  it('is the first two letters of the name, upper case, without one', () => {
    expect(swatchText({ name: 'Avenir Étudiant', acronym: null })).toBe('AV');
    expect(swatchText({ name: 'ensemble', acronym: '' })).toBe('EN');
    expect(swatchText({ name: 'Éclair', acronym: null })).toBe('ÉC');
    expect(swatchText({ name: 'X', acronym: null })).toBe('X');
  });
});
