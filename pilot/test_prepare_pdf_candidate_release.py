import unittest

from prepare_pdf_candidate_release import arithmetic_flags


class ArithmeticTests(unittest.TestCase):
    def test_missing_is_not_zero_and_components_need_not_sum_to_total(self):
        self.assertEqual(arithmetic_flags({'total_population': 10, 'scheduled_castes_population': 4}), [])
        self.assertEqual(arithmetic_flags({'total_population': 10, 'scheduled_castes_population': 4, 'scheduled_tribes_population': 2}), [])

    def test_components_above_total_are_flagged(self):
        self.assertIn('sc_plus_st_exceeds_total', arithmetic_flags({'total_population': 10, 'scheduled_castes_population': 7, 'scheduled_tribes_population': 4}))
        self.assertIn('component_exceeds_total', arithmetic_flags({'total_population': 10, 'scheduled_castes_population': 11}))

    def test_invalid_values_are_not_coerced(self):
        for value in (-1, '10', True, 1.5):
            self.assertIn('total_population_invalid_nonnegative_integer', arithmetic_flags({'total_population': value}))


if __name__ == '__main__':
    unittest.main()
