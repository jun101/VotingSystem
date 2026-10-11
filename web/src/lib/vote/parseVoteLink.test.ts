import { describe, expect, it } from 'vitest';
import { parseVoteLink, voteHref } from './parseVoteLink';

const ID = '0197c8a2-5b1e-7c3a-9d4f-123456789abc';

describe('parseVoteLink', () => {
  it('takes a bare uuid', () => {
    expect(parseVoteLink(` ${ID.toUpperCase()} `)).toEqual({ election: ID, fragment: null });
  });

  it('takes a full link, with or without a code', () => {
    expect(parseVoteLink(`https://vote.example.org/vote/${ID}`)).toEqual({
      election: ID,
      fragment: null,
    });
    expect(parseVoteLink(`https://vote.example.org/vote/${ID}#ABCD-2345`)).toEqual({
      election: ID,
      fragment: 'ABCD-2345',
    });
    expect(parseVoteLink(`http://localhost:8080/vote/${ID}/identity?x=1#K7`)?.election).toBe(ID);
    expect(parseVoteLink(`vote.example.org/vote/${ID}`)?.election).toBe(ID);
  });

  it('builds a path on this site only', () => {
    const link = parseVoteLink(`https://evil.example/vote/${ID}#ABCD`);

    expect(voteHref(link!)).toBe(`/vote/${ID}#ABCD`);
    expect(voteHref({ election: ID, fragment: null })).toBe(`/vote/${ID}`);
  });

  it('refuses anything else', () => {
    for (const bad of [
      '',
      'hello',
      '12345',
      `https://x.org/results/${ID}`,
      `https://x.org/vote/${ID.slice(1)}`,
      `https://x.org/vote/${ID}extra`,
      `javascript:alert(1)//vote/${ID}`,
      `${ID} ${ID}`,
    ]) {
      expect(parseVoteLink(bad), bad).toBeNull();
    }
  });

  it('drops a fragment that does not look like a code', () => {
    expect(parseVoteLink(`${ID}#<script>`)?.fragment).toBeNull();
  });
});
