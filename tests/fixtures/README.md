# Test fixtures

## `swiss-dutch-golden.json`

130 Swiss pairing rounds used by `tests/unit/SwissDutchTest.php`.

Each case is a tournament history (players in pairing-number order, results, byes) plus the pairing
for the next round. They were generated from random tournaments of 4 to 16 players and are only kept
where the plugin's engine and an independent implementation of the FIDE (Dutch) system produced
exactly the same pairings, colours and bye. The independent implementation was
[`@echecs/swiss`](https://github.com/echecsjs/swiss) 5.0.0 (MIT licence), which documents that it is
checked against bbpPairings. It is a development-time reference only and is not part of the plugin.

The cases are regression tests: if a change to `Chess_Army_Knife_Swiss_Dutch` alters any of them,
either the change is wrong or the fixture needs to be re-checked against the FIDE text
(C.04.3).

## `swiss-dutch-large.json`

12 Swiss pairing rounds in fields of 44 to 64 players (same layout as the golden file), each with a score
group of 17 to 19 players. That is larger than the exact optimisation handles directly, so they cover the
search that aims for the colour lower bound and the exact fallback. The expected pairings are those of the
exact optimisation (dynamic programming over subsets) of the engine as it was before that search was
added; the plugin's engine now gives the same pairings, colours and bye, and does not cut the search short.

## `berger-annex.php`

The Berger tables from FIDE General Regulations for Competitions, Annex 1, for 4 to 16 players.

## `lms-v2-results.json`

A small invented league (Test Club A, B and C) in the shape the LMS v2 `event/{id}/results` call
returns: the field names, whole scores as integers and half scores as decimals, a player with a
`null` rating, and a fixture not yet played (`null` scores and winner, no games). It was shaped from a
real response, but every name, rating code and result is made up. Used by
`tests/unit/LeagueDataTest.php`.
