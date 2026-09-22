<?php

/**
 * Default values for Local SEO
 *
 * @package SEOPulse\Modules\LocalSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\LocalSeo;

/**
 * LocalSeoDefaults class
 */
class LocalSeoDefaults
{
    /**
     * Get allowed Schema.org @type values, grouped by business category.
     *
     * The catalogue follows the schema.org/LocalBusiness type hierarchy
     * (https://schema.org/LocalBusiness#subtypes) and Google's guidance to
     * "use the most specific LocalBusiness sub-type possible"
     * (https://developers.google.com/search/docs/appearance/structured-data/local-business).
     *
     * A handful of entries (currently only `VeterinaryCare`) belong to the
     * schema.org health-lifesci extension rather than the core LocalBusiness
     * tree, and therefore don't inherit LocalBusiness properties like
     * `address` or `openingHoursSpecification` on their own. Google
     * explicitly supports declaring `@type` as an array for this kind of
     * case ("If you have multiple types, specify them as an array
     * (`additionalType` isn't supported)"), so these are flagged in
     * {@see self::get_extension_only_types()} to encourage pairing them with
     * `LocalBusiness` or another core subtype, e.g.:
     * `"@type": ["VeterinaryCare", "LocalBusiness"]`.
     *
     * @return array<string, array{label: string, types: array<string, string>}>
     */
    public static function get_allowed_types_grouped(): array
    {
        return [
            'general'                       => [
                'label' => __('General', 'seopulse'),
                'types' => [
                    'LocalBusiness' => __('Local Business (generic)', 'seopulse'),
                    'Organization'  => __('Organization', 'seopulse'),
                    'Person'        => __('Person', 'seopulse'),
                    'Place'         => __('Place', 'seopulse'),
                ],
            ],
            'food_drink'                    => [
                'label' => __('Food & Drink', 'seopulse'),
                'types' => [
                    'FoodEstablishment'  => __('Food Establishment (generic)', 'seopulse'),
                    'Restaurant'         => __('Restaurant', 'seopulse'),
                    'Bakery'             => __('Bakery', 'seopulse'),
                    'BarOrPub'           => __('Bar or Pub', 'seopulse'),
                    'Brewery'            => __('Brewery', 'seopulse'),
                    'CafeOrCoffeeShop'   => __('Cafe or Coffee Shop', 'seopulse'),
                    'Distillery'         => __('Distillery', 'seopulse'),
                    'FastFoodRestaurant' => __('Fast Food Restaurant', 'seopulse'),
                    'IceCreamShop'       => __('Ice Cream Shop', 'seopulse'),
                    'Winery'             => __('Winery', 'seopulse'),
                ],
            ],
            'health_medical'                => [
                'label' => __('Health & Medical', 'seopulse'),
                'types' => [
                    'MedicalBusiness' => __('Medical Business (generic)', 'seopulse'),
                    'MedicalClinic'   => __('Medical Clinic', 'seopulse'),
                    'Physician'       => __('Physician', 'seopulse'),
                    'Dentist'         => __('Dentist', 'seopulse'),
                    'Pharmacy'        => __('Pharmacy', 'seopulse'),
                    'Optician'        => __('Optician', 'seopulse'),
                    'Optometric'      => __('Optometrist', 'seopulse'),
                    'Ophthalmology'   => __('Ophthalmologist', 'seopulse'),
                    'Dermatology'     => __('Dermatologist', 'seopulse'),
                    'PlasticSurgery'  => __('Plastic Surgeon', 'seopulse'),
                    'Podiatric'       => __('Podiatrist', 'seopulse'),
                    'Psychiatric'     => __('Psychiatric / Mental Health', 'seopulse'),
                    'Gynecologic'     => __('Gynecologist', 'seopulse'),
                    'Obstetric'       => __('Obstetrician', 'seopulse'),
                    'Pediatric'       => __('Pediatrician', 'seopulse'),
                    'Geriatric'       => __('Geriatric Care', 'seopulse'),
                    'Oncologic'       => __('Oncologist', 'seopulse'),
                    'Otolaryngologic' => __('Otolaryngologist (ENT)', 'seopulse'),
                    'Audiology'       => __('Audiologist', 'seopulse'),
                    'Physiotherapy'   => __('Physiotherapist', 'seopulse'),
                    'DietNutrition'   => __('Dietitian / Nutritionist', 'seopulse'),
                    'Midwifery'       => __('Midwife', 'seopulse'),
                    'Nursing'         => __('Nursing Care', 'seopulse'),
                    'PrimaryCare'     => __('Primary Care', 'seopulse'),
                    'PublicHealth'    => __('Public Health', 'seopulse'),
                    'CommunityHealth' => __('Community Health', 'seopulse'),
                    // health-lifesci extension type — pair with LocalBusiness. See class docblock note above.
                    'VeterinaryCare'  => __('Veterinarian', 'seopulse'),
                ],
            ],
            'health_beauty'                 => [
                'label' => __('Health & Beauty', 'seopulse'),
                'types' => [
                    'HealthAndBeautyBusiness' => __('Health & Beauty (generic)', 'seopulse'),
                    'BeautySalon'             => __('Beauty Salon', 'seopulse'),
                    'DaySpa'                  => __('Day Spa', 'seopulse'),
                    'HairSalon'               => __('Hair Salon', 'seopulse'),
                    'HealthClub'              => __('Health Club / Gym', 'seopulse'),
                    'NailSalon'               => __('Nail Salon', 'seopulse'),
                    'TattooParlor'            => __('Tattoo Parlor', 'seopulse'),
                ],
            ],
            'lodging_travel'                => [
                'label' => __('Lodging & Travel', 'seopulse'),
                'types' => [
                    'LodgingBusiness'         => __('Lodging Business (generic)', 'seopulse'),
                    'Hotel'                   => __('Hotel', 'seopulse'),
                    'Motel'                   => __('Motel', 'seopulse'),
                    'BedAndBreakfast'         => __('Bed & Breakfast', 'seopulse'),
                    'Hostel'                  => __('Hostel', 'seopulse'),
                    'Resort'                  => __('Resort', 'seopulse'),
                    'SkiResort'               => __('Ski Resort', 'seopulse'),
                    'Campground'              => __('Campground', 'seopulse'),
                    'VacationRental'          => __('Vacation Rental', 'seopulse'),
                    'TravelAgency'            => __('Travel Agency', 'seopulse'),
                    'TouristInformationCenter' => __('Tourist Information Center', 'seopulse'),
                ],
            ],
            'automotive'                    => [
                'label' => __('Automotive', 'seopulse'),
                'types' => [
                    'AutomotiveBusiness' => __('Automotive Business (generic)', 'seopulse'),
                    'AutoDealer'         => __('Car Dealer', 'seopulse'),
                    'AutoRepair'         => __('Auto Repair Shop', 'seopulse'),
                    'AutoBodyShop'       => __('Auto Body Shop', 'seopulse'),
                    'AutoPartsStore'     => __('Auto Parts Store', 'seopulse'),
                    'AutoRental'         => __('Car Rental', 'seopulse'),
                    'AutoWash'           => __('Car Wash', 'seopulse'),
                    'GasStation'         => __('Gas Station', 'seopulse'),
                    'MotorcycleDealer'   => __('Motorcycle Dealer', 'seopulse'),
                    'MotorcycleRepair'   => __('Motorcycle Repair', 'seopulse'),
                ],
            ],
            'professional_legal_financial'  => [
                'label' => __('Professional, Legal & Financial', 'seopulse'),
                'types' => [
                    'ProfessionalService' => __('Professional Service (generic)', 'seopulse'),
                    'LegalService'        => __('Legal Service (generic)', 'seopulse'),
                    'Attorney'            => __('Attorney / Law Firm', 'seopulse'),
                    'Notary'              => __('Notary', 'seopulse'),
                    'FinancialService'    => __('Financial Service (generic)', 'seopulse'),
                    'AccountingService'   => __('Accounting Service', 'seopulse'),
                    'BankOrCreditUnion'   => __('Bank or Credit Union', 'seopulse'),
                    'InsuranceAgency'     => __('Insurance Agency', 'seopulse'),
                    'AutomatedTeller'     => __('ATM', 'seopulse'),
                    'EmploymentAgency'    => __('Employment Agency', 'seopulse'),
                    'RealEstateAgent'     => __('Real Estate Agent', 'seopulse'),
                ],
            ],
            'home_construction'             => [
                'label' => __('Home & Construction Services', 'seopulse'),
                'types' => [
                    'HomeAndConstructionBusiness' => __('Home & Construction (generic)', 'seopulse'),
                    'Electrician'                 => __('Electrician', 'seopulse'),
                    'Plumber'                      => __('Plumber', 'seopulse'),
                    'HVACBusiness'                 => __('HVAC Business', 'seopulse'),
                    'GeneralContractor'            => __('General Contractor', 'seopulse'),
                    'RoofingContractor'            => __('Roofing Contractor', 'seopulse'),
                    'HousePainter'                 => __('House Painter', 'seopulse'),
                    'Locksmith'                    => __('Locksmith', 'seopulse'),
                    'MovingCompany'                => __('Moving Company', 'seopulse'),
                    'DryCleaningOrLaundry'         => __('Dry Cleaning or Laundry', 'seopulse'),
                    'SelfStorage'                  => __('Self Storage Facility', 'seopulse'),
                ],
            ],
            'retail_shopping'               => [
                'label' => __('Retail & Shopping', 'seopulse'),
                'types' => [
                    'Store'               => __('Store (generic)', 'seopulse'),
                    'ShoppingCenter'      => __('Shopping Center', 'seopulse'),
                    'ClothingStore'       => __('Clothing Store', 'seopulse'),
                    'MensClothingStore'   => __('Men\'s Clothing Store', 'seopulse'),
                    'ShoeStore'           => __('Shoe Store', 'seopulse'),
                    'JewelryStore'        => __('Jewelry Store', 'seopulse'),
                    'BookStore'           => __('Book Store', 'seopulse'),
                    'BikeStore'           => __('Bike Store', 'seopulse'),
                    'ComputerStore'       => __('Computer Store', 'seopulse'),
                    'ElectronicsStore'    => __('Electronics Store', 'seopulse'),
                    'MobilePhoneStore'    => __('Mobile Phone Store', 'seopulse'),
                    'ConvenienceStore'    => __('Convenience Store', 'seopulse'),
                    'GroceryStore'        => __('Grocery Store', 'seopulse'),
                    'LiquorStore'         => __('Liquor Store', 'seopulse'),
                    'DepartmentStore'     => __('Department Store', 'seopulse'),
                    'OutletStore'         => __('Outlet Store', 'seopulse'),
                    'WholesaleStore'      => __('Wholesale Store', 'seopulse'),
                    'PawnShop'            => __('Pawn Shop', 'seopulse'),
                    'Florist'             => __('Florist', 'seopulse'),
                    'FurnitureStore'      => __('Furniture Store', 'seopulse'),
                    'HomeGoodsStore'      => __('Home Goods Store', 'seopulse'),
                    'HardwareStore'       => __('Hardware Store', 'seopulse'),
                    'GardenStore'         => __('Garden Store', 'seopulse'),
                    'PetStore'            => __('Pet Store', 'seopulse'),
                    'ToyStore'            => __('Toy Store', 'seopulse'),
                    'HobbyShop'           => __('Hobby Shop', 'seopulse'),
                    'MusicStore'          => __('Music Store', 'seopulse'),
                    'MovieRentalStore'    => __('Movie Rental Store', 'seopulse'),
                    'SportingGoodsStore'  => __('Sporting Goods Store', 'seopulse'),
                    'TireShop'            => __('Tire Shop', 'seopulse'),
                    'OfficeEquipmentStore' => __('Office Equipment Store', 'seopulse'),
                ],
            ],
            'sports_fitness'                => [
                'label' => __('Sports, Fitness & Recreation', 'seopulse'),
                'types' => [
                    'SportsActivityLocation' => __('Sports Activity Location (generic)', 'seopulse'),
                    'ExerciseGym'            => __('Gym', 'seopulse'),
                    'SportsClub'             => __('Sports Club', 'seopulse'),
                    'BowlingAlley'           => __('Bowling Alley', 'seopulse'),
                    'GolfCourse'             => __('Golf Course', 'seopulse'),
                    'PublicSwimmingPool'     => __('Public Swimming Pool', 'seopulse'),
                    'TennisComplex'          => __('Tennis Complex', 'seopulse'),
                    'StadiumOrArena'         => __('Stadium or Arena', 'seopulse'),
                ],
            ],
            'entertainment_arts'            => [
                'label' => __('Entertainment & Arts', 'seopulse'),
                'types' => [
                    'EntertainmentBusiness' => __('Entertainment Business (generic)', 'seopulse'),
                    'ArtGallery'            => __('Art Gallery', 'seopulse'),
                    'MovieTheater'          => __('Movie Theater', 'seopulse'),
                    'NightClub'             => __('Night Club', 'seopulse'),
                    'ComedyClub'            => __('Comedy Club', 'seopulse'),
                    'AmusementPark'         => __('Amusement Park', 'seopulse'),
                    'Casino'                => __('Casino', 'seopulse'),
                    'AdultEntertainment'    => __('Adult Entertainment', 'seopulse'),
                ],
            ],
            'community_education'           => [
                'label' => __('Community, Education & Public Services', 'seopulse'),
                'types' => [
                    'Library'             => __('Library', 'seopulse'),
                    'ChildCare'           => __('Child Care', 'seopulse'),
                    'AnimalShelter'       => __('Animal Shelter', 'seopulse'),
                    'ArchiveOrganization' => __('Archive Organization', 'seopulse'),
                    'GovernmentOffice'    => __('Government Office (generic)', 'seopulse'),
                    'PostOffice'          => __('Post Office', 'seopulse'),
                    'EmergencyService'    => __('Emergency Service (generic)', 'seopulse'),
                    'FireStation'         => __('Fire Station', 'seopulse'),
                    'PoliceStation'       => __('Police Station', 'seopulse'),
                    'Hospital'            => __('Hospital', 'seopulse'),
                    'RecyclingCenter'     => __('Recycling Center', 'seopulse'),
                    'InternetCafe'        => __('Internet Cafe', 'seopulse'),
                ],
            ],
            'media'                         => [
                'label' => __('Media', 'seopulse'),
                'types' => [
                    'RadioStation'      => __('Radio Station', 'seopulse'),
                    'TelevisionStation' => __('Television Station', 'seopulse'),
                ],
            ],
        ];
    }

    /**
     * Get allowed types from Schema.org, as a flat `@type` => label map.
     *
     * This flattens {@see self::get_allowed_types_grouped()} in category
     * order and is kept for backward compatibility: it's what the settings
     * screen localizes as `allowedTypes` and what the validator uses to
     * build its allow-list.
     *
     * @return array<string, string>
     */
    public static function get_allowed_types(): array
    {
        $flat = [];

        foreach (self::get_allowed_types_grouped() as $group) {
            foreach ($group['types'] as $type => $label) {
                $flat[ $type ] = $label;
            }
        }

        return $flat;
    }

    /**
     * Get allowed `@type` values only (no translated labels).
     *
     * Cheaper than {@see self::get_allowed_types()} when only the allow-list
     * of Schema.org identifiers is needed, e.g. for sanitization.
     *
     * @return string[]
     */
    public static function get_allowed_type_keys(): array
    {
        $keys = [];

        foreach (self::get_allowed_types_grouped() as $group) {
            foreach (array_keys($group['types']) as $type) {
                $keys[] = $type;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Types that belong to a Schema.org extension (e.g. health-lifesci)
     * rather than the core LocalBusiness tree, and therefore don't inherit
     * LocalBusiness properties (address, openingHoursSpecification, etc.)
     * on their own. These are good candidates to combine with a core type
     * via the multi-@type array, e.g. `["VeterinaryCare", "LocalBusiness"]`.
     *
     * @return string[]
     */
    public static function get_extension_only_types(): array
    {
        return ['VeterinaryCare'];
    }

    /**
     * Days of the week for opening hours
     *
     * @return array
     */
    public static function get_days_of_week(): array
    {
        return [
            'Monday'    => __('Monday', 'seopulse'),
            'Tuesday'   => __('Tuesday', 'seopulse'),
            'Wednesday' => __('Wednesday', 'seopulse'),
            'Thursday'  => __('Thursday', 'seopulse'),
            'Friday'    => __('Friday', 'seopulse'),
            'Saturday'  => __('Saturday', 'seopulse'),
            'Sunday'    => __('Sunday', 'seopulse'),
        ];
    }

    /**
     * Get default settings
     *
     * @return array
     */
    public static function get_default_settings(): array
    {
        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            'name'        => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url'         => home_url(),
        ];
    }
}
