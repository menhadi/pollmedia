import unittest
from link_mohua_urban_census import norm,town_name
class IdentityTests(unittest.TestCase):
 def test_administrative_census_suffix_only(self):
  self.assertEqual('nipani',town_name('Nipani (CMC)'))
  self.assertEqual('amritsar',town_name('Amritsar (M Corp. + OG) (Part)'))
 def test_disambiguating_suffix_is_not_removed(self):
  self.assertIsNone(town_name('Gudur (Kurnool)'))
  self.assertIsNone(town_name('Example (R)'))
 def test_no_fuzzy_spelling(self):
  self.assertNotEqual(norm('Bangalore'),norm('Bengaluru'))
  self.assertNotEqual(norm('Nipani'),norm('Nippani'))
if __name__=='__main__':unittest.main()
