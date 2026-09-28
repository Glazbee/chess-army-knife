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

## `berger-annex.php`

The Berger tables from FIDE General Regulations for Competitions, Annex 1, for 4 to 16 players.
