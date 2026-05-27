<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure roles & permissions are created first
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

        // --- 1. Define All Organizations Data ---
        $organizationsData = [
            [
                'name' => 'Ismax Security',
                'email' => 'chief-operations@ismaxsecurity.com',
                'phone_number' => '+254795704301',
                'address' => 'Ismax Security Limited, Mombasa Road',
                'role' => 'admin',
                'user_name' => 'Ismax Security',
            ],
        ];

        // --- 2. Seed Test Organization (SUPER-ADMIN) ---
        $testOrganization = Organization::firstOrCreate(
            ['email' => 'techsupport@identigate.co.ke'],
            [
                'name' => 'Identigate',
                'phone_number' => '254795704301',
            ]
        );

        $testUser = User::firstOrCreate(
            ['email' => $testOrganization->email],
            [
                'name' => 'Tech Support',
                'password' => bcrypt('Test#2025'),
            ]
        );

        // Assign super-admin role
        $testUser->assignRole('super-admin');

        // --- 3. Seed Related Records for Test Org ---
        $shift = Shift::firstOrCreate(
            ['organization_id' => $testOrganization->id, 'name' => 'Morning Shift'],
            [
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'break_minutes' => 60,
                'overtime_rate' => 1.5,
                'status' => 'active',
                'notes' => 'Standard 8-hour day shift with 60-minute break.',
            ]
        );

        $department = Department::firstOrCreate(
            ['organization_id' => $testOrganization->id, 'name' => 'ICT'],
            [
                'description' => 'ICT',
                'manager_id' => $testUser->id,
            ]
        );

        Employee::firstOrCreate(
            ['organization_id' => $testOrganization->id, 'user_id' => $testUser->id],
            [
                'department_id' => $department->id,
                'shift_id' => $shift->id,
                'name' => 'Tech Support',
                'id_number' => 'EMP999',
                'email' => $testUser->email,
                'phone' => '254712345678',
                'active' => true,
            ]
        );

        // --- 4. Loop Through New Organizations (ADMINS) ---
        foreach ($organizationsData as $data) {

            // a. Create/Get Organization
            $org = Organization::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone_number' => $data['phone_number'],
                    'address' => $data['address'],
                ]
            );

            // b. Create/Get User for this Organization (admin)
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['user_name'],
                    'password' => bcrypt('password'), // organization admin password (you can change)
                ]
            );

            // c. Assign Admin role
            $user->syncRoles(['admin']);

            // d. Create a default Shift/Department/Employee for the new orgs
            $defaultShift = Shift::firstOrCreate(
                ['organization_id' => $org->id, 'name' => 'Default Shift'],
                [
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'break_minutes' => 30,
                    'overtime_rate' => 1.0,
                    'status' => 'active',
                ]
            );

            $defaultDept = Department::firstOrCreate(
                ['organization_id' => $org->id, 'name' => 'Operations'],
                [
                    'description' => 'Operations / Security',
                    'manager_id' => $user->id,
                ]
            );

            Employee::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $user->id],
                [
                    'department_id' => $defaultDept->id,
                    'shift_id' => $defaultShift->id,
                    'name' => $data['user_name'],
                    'id_number' => 'ADM-' . $org->id,
                    'email' => $user->email,
                    'phone' => $data['phone_number'],
                    'active' => true,
                ]
            );

            // --- 5. Seed All Staff for Ismax Security (from uploaded doc) ---
            // Default password for staff users (change if desired)
            $defaultStaffPassword = 'Ismax#2025';

            // All staff entries (name + job_title). 192 entries from the uploaded doc.
            $staffMembers = [
                ['name' => 'ALLEN KAMMINGS ESIPECHE', 'job_title' => 'SENIOR PROJECT MANAGER'],
                ['name' => 'SAMWEL ONYANGO OWENGA', 'job_title' => 'SENIOR SUPERVISOR'],
                ['name' => 'PETERSON OGAMBA SAGWE', 'job_title' => 'SUPERVISOR'],
                ['name' => 'LOICE KWANALO', 'job_title' => 'SUPERVISOR'],
                ['name' => 'ANDREW MUSUNDI KHAMASI', 'job_title' => 'SUPERVISOR'],
                ['name' => 'VINCENT KIPYEGON MUTAI', 'job_title' => 'GUARD'],
                ['name' => 'JUMA SIRENGO', 'job_title' => 'GUARD'],
                ['name' => 'MOSES OKWARO', 'job_title' => 'GUARD'],
                ['name' => 'BENARD OUMA WAKULIMA', 'job_title' => 'GUARD'],
                ['name' => 'BENHARD BRUNO', 'job_title' => 'GUARD'],
                ['name' => 'BENJAMIN OGAO MOKEBO', 'job_title' => 'GUARD'],
                ['name' => 'RONALD KIPRONO CHEPKWONY', 'job_title' => 'GUARD'],
                ['name' => 'OSCAR WEKESA', 'job_title' => 'GUARD'],
                ['name' => 'BONFACE WEKESA', 'job_title' => 'GUARD'],
                ['name' => 'KEVIN OTIENO', 'job_title' => 'GUARD'],
                ['name' => 'JOSHUA LESALON', 'job_title' => 'GUARD'],
                ['name' => 'CHARLES OLUOCH OPONDO', 'job_title' => 'GUARD'],
                ['name' => 'DAMARIS WANGUI', 'job_title' => 'GUARD'],
                ['name' => 'KEITH ALU KIGUMBA', 'job_title' => 'GUARD'],
                ['name' => 'CHRISTOPHER OBOKA', 'job_title' => 'GUARD'],
                ['name' => 'VIVIAN EPHY ONYANGO', 'job_title' => 'GUARD'],
                ['name' => 'MONICAH ACHIENG ODUOR', 'job_title' => 'GUARD'],
                ['name' => 'WILLIAM BARASA WAMBWA', 'job_title' => 'GUARD'],
                ['name' => 'FELIX ODIRA RAILA', 'job_title' => 'GUARD'],
                ['name' => 'SELLY CHEPTEKEI', 'job_title' => 'GUARD'],
                ['name' => 'VICTOR OGEGA NYANCHOKA', 'job_title' => 'GUARD'],
                ['name' => 'MERCY LIKANDA', 'job_title' => 'GUARD'],
                ['name' => 'DOMINIC OTIENO OTIENO', 'job_title' => 'GUARD'],
                ['name' => 'DOROTHY MUTETI', 'job_title' => 'GUARD'],
                ['name' => 'DOREEN ADHIAMBO SIANGANI', 'job_title' => 'GUARD'],
                ['name' => 'ERICK MUHENGE', 'job_title' => 'GUARD'],
                ['name' => 'JOSPHINE KANANA KINYU', 'job_title' => 'GUARD'],
                ['name' => 'KEMBOI KIBET KENNEDY', 'job_title' => 'GUARD'],
                ['name' => 'EVANS NYACHOTI KEBWARO', 'job_title' => 'GUARD'],
                ['name' => 'CHARLES MUINDE', 'job_title' => 'GUARD'],
                ['name' => 'FAITH WANJIRU', 'job_title' => 'GUARD'],
                ['name' => 'VITALIS OKELLO OBUYA', 'job_title' => 'GUARD'],
                ['name' => 'ABDIAZIZ GODANA ALI', 'job_title' => 'GUARD'],
                ['name' => 'GEOFRY KIPKOECH KIRUI', 'job_title' => 'GUARD'],
                ['name' => 'EMMANUEL ISIGI', 'job_title' => 'GUARD'],
                ['name' => 'AMBROSE SIMIYU NAMACHANJA', 'job_title' => 'GUARD'],
                ['name' => 'GEORGE OGADA', 'job_title' => 'GUARD'],
                ['name' => 'GIDEON NGAATU MUTUA', 'job_title' => 'GUARD'],
                ['name' => 'GILBERT BETT', 'job_title' => 'GUARD'],
                ['name' => 'HELLEN KIPSISEI', 'job_title' => 'GUARD'],
                ['name' => 'DOMINIC MASESE', 'job_title' => 'GUARD'],
                ['name' => 'HYLINE KERUBO', 'job_title' => 'GUARD'],
                ['name' => 'GIDEON KOROS', 'job_title' => 'GUARD'],
                ['name' => 'ISABELLAH MILOYO', 'job_title' => 'GUARD'],
                ['name' => 'ALFONCE ACHEKEDE OKWARA', 'job_title' => 'GUARD'],
                ['name' => 'ALEXANDER SUMBA', 'job_title' => 'GUARD'],
                ['name' => 'WELDON KIBET', 'job_title' => 'GUARD'],
                ['name' => 'JOASH WANGILA', 'job_title' => 'GUARD'],
                ['name' => 'JOHN KIIRU WACHERA', 'job_title' => 'GUARD'],
                ['name' => 'ALICE JOSEPH', 'job_title' => 'GUARD'],
                ['name' => 'JOSEPH OKUMU OBWOLLO', 'job_title' => 'GUARD'],
                ['name' => 'ALIVITSA FAITH', 'job_title' => 'GUARD'],
                ['name' => 'NOEL WEYUSIA', 'job_title' => 'GUARD'],
                ['name' => 'JUDITH ATIENO', 'job_title' => 'GUARD'],
                ['name' => 'JUSTINE NAMAYEMBA PAMBA', 'job_title' => 'GUARD'],
                ['name' => 'KELVIN ONYANGO AIKO', 'job_title' => 'GUARD'],
                ['name' => 'NATALI STECY', 'job_title' => 'GUARD'],
                ['name' => 'ANGELA VAATI', 'job_title' => 'GUARD'],
                ['name' => 'DAVID SIMIYU', 'job_title' => 'GUARD'],
                ['name' => 'KENNEDY NDIRANGU NDUTA', 'job_title' => 'GUARD'],
                ['name' => 'DENNIS NGETICH', 'job_title' => 'GUARD'],
                ['name' => 'SAMWEL KIDAJI KODEYO', 'job_title' => 'GUARD'],
                ['name' => 'FELIX BABU ONCHONGA', 'job_title' => 'GUARD'],
                ['name' => 'GETRUDE AYESA', 'job_title' => 'GUARD'],
                ['name' => 'LEONARD OLUOCH', 'job_title' => 'GUARD'],
                ['name' => 'OKEYO OUMA WYCLIFFE', 'job_title' => 'GUARD'],
                ['name' => 'RODGERS OCHIENG OGONGO', 'job_title' => 'GUARD'],
                ['name' => 'DISMAS KIBET KIPRUTO', 'job_title' => 'GUARD'],
                ['name' => 'CRINOLINE ADHIAMBO', 'job_title' => 'GUARD'],
                ['name' => 'TERESA WAMBUI MAINA', 'job_title' => 'GUARD'],
                ['name' => 'AMBROSE NZUKI', 'job_title' => 'GUARD'],
                ['name' => 'MERCY KANYUA MUTURI', 'job_title' => 'GUARD'],
                ['name' => 'SHIVACHI LITINYI SOLOMON', 'job_title' => 'GUARD'],
                ['name' => 'OBED GISEMBA OMBWORI', 'job_title' => 'GUARD'],
                ['name' => 'AGGREY BOSIRE', 'job_title' => 'GUARD'],
                ['name' => 'NANCY MORAA ABUGA', 'job_title' => 'GUARD'],
                ['name' => 'GEOFFREY LISAMBU', 'job_title' => 'GUARD'],
                ['name' => 'MOHAMED RASHID IBRAHIM', 'job_title' => 'GUARD'],
                ['name' => 'ALEX NDERITU', 'job_title' => 'GUARD'],
                ['name' => 'SAMWEL MARICHA ZACHARIA', 'job_title' => 'GUARD'],
                ['name' => 'ROBERT WANYAMA OSAMA', 'job_title' => 'GUARD'],
                ['name' => 'PAUL NANGOLE', 'job_title' => 'GUARD'],
                ['name' => 'LTAKINES LENGUPAE PETER', 'job_title' => 'GUARD'],
                ['name' => 'MONICAH NJUNGUNA', 'job_title' => 'GUARD'],
                ['name' => 'JOHN MWENDWA', 'job_title' => 'GUARD'],
                ['name' => 'NAOMI KERUBO MOGERE', 'job_title' => 'GUARD'],
                ['name' => 'PHILIP ONYIEGO', 'job_title' => 'GUARD'],
                ['name' => 'KAHURU ESTHER WANKURU', 'job_title' => 'GUARD'],
                ['name' => 'PRISCAH KANYINGI', 'job_title' => 'GUARD'],
                ['name' => 'BARNABAS ODONGO OOKO', 'job_title' => 'GUARD'],
                ['name' => 'JOSEPH CHESONI LEPEYIO', 'job_title' => 'GUARD'],
                ['name' => 'REUBEN ONSARE', 'job_title' => 'GUARD'],
                ['name' => 'BENARD KIPKIRUI LANGAT', 'job_title' => 'GUARD'],
                ['name' => 'NICHOLAS OWINO OCHOLA', 'job_title' => 'GUARD'],
                ['name' => 'PATRICK OCHIENG NYAMALO', 'job_title' => 'GUARD'],
                ['name' => 'GILBERT MUTAI', 'job_title' => 'GUARD'],
                ['name' => 'ROBERT SADIA MUGABE', 'job_title' => 'GUARD'],
                ['name' => 'LYDIA MMBONE', 'job_title' => 'GUARD'],
                ['name' => 'PETER RIOBA', 'job_title' => 'GUARD'],
                ['name' => 'ROBERT LOLONGWASO', 'job_title' => 'GUARD'],
                ['name' => 'SILAS LERIONKA MASAMPE', 'job_title' => 'GUARD'],
                ['name' => 'WALTER SAGINI', 'job_title' => 'GUARD'],
                ['name' => 'BRIAN MUSILI MUTUA', 'job_title' => 'GUARD'],
                ['name' => 'DAVID NGERESO', 'job_title' => 'GUARD'],
                ['name' => 'NIXON OPANGA', 'job_title' => 'GUARD'],
                ['name' => 'JACKLINE MILIMU', 'job_title' => 'GUARD'],
                ['name' => 'VICTOR NYANGAU', 'job_title' => 'GUARD'],
                ['name' => 'VINCENT ODIMA', 'job_title' => 'GUARD'],
                ['name' => 'VIOLET OTANGA', 'job_title' => 'GUARD'],
                ['name' => 'MARGARET WAHINGA NDIRANGU', 'job_title' => 'GUARD'],
                ['name' => 'WESLEY NYINGI', 'job_title' => 'GUARD'],
                ['name' => 'EVELINE KAMENE MWONGELI', 'job_title' => 'GUARD'],
                ['name' => 'CHESIR CHERUYIOT BRIAN', 'job_title' => 'GUARD'],
                ['name' => 'BILL CLINTONE RAPALA', 'job_title' => 'GUARD'],
                ['name' => 'DAVIES MASAI', 'job_title' => 'GUARD'],
                ['name' => 'WYCLIFFE MUGENI', 'job_title' => 'GUARD'],
                ['name' => 'HELLEN SIRENGO', 'job_title' => 'GUARD'],
                ['name' => 'JERYADINE A. KAMOYA', 'job_title' => 'GUARD'],
                ['name' => 'ISAAC NYAANGA', 'job_title' => 'GUARD'],
                ['name' => 'MARGRET MBATHA', 'job_title' => 'GUARD'],
                ['name' => 'BENSON MBURU', 'job_title' => 'GUARD'],
                ['name' => 'TITUS KILONZO', 'job_title' => 'GUARD'],
                ['name' => 'RAYMOND MBANE', 'job_title' => 'GUARD'],
                ['name' => 'ROBERT KIPTUM', 'job_title' => 'GUARD'],
                ['name' => 'ZAINAB ALI JEFFAR', 'job_title' => 'GUARD'],
                ['name' => 'CLINTON MWIKWABE CHALANJI', 'job_title' => 'GUARD'],
                ['name' => 'FRANCIS OKANGA', 'job_title' => 'GUARD'],
                ['name' => 'CAROLYNE BARASA', 'job_title' => 'GUARD'],
                ['name' => 'JOASH WAKUKHA SHALO', 'job_title' => 'GUARD'],
                ['name' => 'FELIX KIMTAI NDIEMA', 'job_title' => 'GUARD'],
                ['name' => 'PATRICK ONYANGO ODUNGA', 'job_title' => 'GUARD'],
                ['name' => 'SYLVIA NYATUKA OSORO', 'job_title' => 'GUARD'],
                ['name' => 'HARRIET IROSA', 'job_title' => 'GUARD'],
                ['name' => 'JOHN LIMUJUMBEN', 'job_title' => 'GUARD'],
                ['name' => 'ROBERT OUKO', 'job_title' => 'GUARD'],
                ['name' => 'JEMIMAH MATANDA', 'job_title' => 'GUARD'],
                ['name' => 'SAMWEL MOGAKA', 'job_title' => 'GUARD'],
                ['name' => 'MARK SHIKUKU TIYOI', 'job_title' => 'GUARD'],
                ['name' => 'ALPHONES OKUKU OTIENIO', 'job_title' => 'GUARD'],
                ['name' => 'MARTIN LIASUBILA LUSINDE', 'job_title' => 'GUARD'],
                ['name' => 'JACKSON MAINA MUTHONI', 'job_title' => 'GUARD'],
                ['name' => 'GEOFFREY OTIENO OMWANDA', 'job_title' => 'GUARD'],
                ['name' => 'ERICK OWINO JUMA', 'job_title' => 'GUARD'],
                ['name' => 'MERTINA NYANGESO OPUKEN', 'job_title' => 'GUARD'],
                ['name' => 'JOSEPH NJENGA NJERI', 'job_title' => 'GUARD'],
                ['name' => 'CYNTHIA NANG\'UNI', 'job_title' => 'GUARD'],
                ['name' => 'DENNIS OMONDI', 'job_title' => 'GUARD'],
                ['name' => 'DANIS OMONDI ONYANGO', 'job_title' => 'GUARD'],
                ['name' => 'KENNEDY WAFULA WANYAMA', 'job_title' => 'GUARD'],
                ['name' => 'DAUDI KURGAT', 'job_title' => 'GUARD'],
                ['name' => 'JOSEPH MUYA JOSHUA', 'job_title' => 'GUARD'],
                ['name' => 'DENNIS CHERUYIOT BETT', 'job_title' => 'GUARD'],
                ['name' => 'ABNER KEGENGO SAMWEL', 'job_title' => 'GUARD'],
                ['name' => 'IRINE CHEPKIRUI', 'job_title' => 'GUARD'],
                ['name' => 'THOMAS INGOLO', 'job_title' => 'GUARD'],
                ['name' => 'TONUI KIPLAGAT MICHAEL', 'job_title' => 'GUARD'],
                ['name' => 'FELIX KIPKORIR', 'job_title' => 'GUARD'],
                ['name' => 'COLLINS KHAMASI SAVATIA', 'job_title' => 'GUARD'],
                ['name' => 'HEZRON KIPGETICH MOSONIK', 'job_title' => 'GUARD'],
                ['name' => 'KENNEDY ITAPAR', 'job_title' => 'GUARD'],
                ['name' => 'SHARON CHELANGAT', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'ELIZABETH SHIRWATSO', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'NAJMA ABDI', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'EVERLYN ADHIAMBO ODOOR', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'JUDITH AKINYI', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'LYDIA AYAKO HOKA', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'LILIAN ISEREN SAGINI', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'LEAH MASIKA RANIA', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'SARAH MWENDE NTHENYA', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'MIRIAM MUNJALU KHASOA', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'YVONNE BWANGO BARASA', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'NANCY MWENDE RUGENDO', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'SYLIVESTER LEACKEY OKUMU', 'job_title' => 'CUSTOMER CARE'],
                ['name' => 'DALMAS OCHIENO', 'job_title' => 'GUARD'],
                ['name' => 'LUCAS ASAKA ODERA', 'job_title' => 'GUARD'],
                ['name' => 'SHEILA CHEPGENO', 'job_title' => 'GUARD'],
                ['name' => 'JARED NYABIYA NYAGAKA', 'job_title' => 'GUARD'],
                ['name' => 'MERCY NYAMAI', 'job_title' => 'GUARD'],
                ['name' => 'CLINTON MOGIRE NYANGWARA', 'job_title' => 'GUARD'],
                ['name' => 'GLADYS MBURU WAMORO', 'job_title' => 'GUARD'],
                ['name' => 'JACKLINE MWITA', 'job_title' => 'GUARD'],
                ['name' => 'DALMAS MURITHI', 'job_title' => 'GUARD'],
                ['name' => 'BELINDA MAKENA', 'job_title' => 'GUARD'],
                ['name' => 'JACKTON OTIENO OCHIENG', 'job_title' => 'GUARD'],
                ['name' => 'DOMINIC KIRUI', 'job_title' => 'GUARD'],
                ['name' => 'ROBERT OUKO ODERO', 'job_title' => 'GUARD'],
                ['name' => 'DOMINIC OMONGIN', 'job_title' => 'GUARD'],
            ];

            // Counter for generating unique id_numbers per staff
            $counter = 1;

            foreach ($staffMembers as $staff) {
                // Determine role
                $title = strtolower($staff['job_title']);
                $role = 'employee';
                if (strpos($title, 'supervisor') !== false || strpos($title, 'manager') !== false) {
                    $role = 'supervisor';
                }
                // sanitize name for email local part
                $cleanName = preg_replace('/[^A-Za-z0-9\s\-\.]/', '', $staff['name']); // remove odd chars
                $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));
                $local = strtolower(str_replace(' ', '.', $cleanName));
                // collapse multiple dots
                $local = preg_replace('/\.+/', '.', $local);
                // remove leading/trailing dots
                $local = trim($local, '.');
                // fallback if empty
                if (empty($local)) {
                    $local = 'user' . $counter;
                }
                $email = $local . '@ismaxsecurity.com';

                // Create or fetch user
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => ucwords(strtolower($staff['name'])),
                        'password' => bcrypt($defaultStaffPassword),
                    ]
                );

                // Assign role (use syncRoles to ensure single role)
                try {
                    $user->syncRoles([$role]);
                } catch (\Throwable $e) {
                    // If roles not present, skip role assignment but continue seeding
                    // You may want to ensure RolesAndPermissionsSeeder created these roles
                }

                // Create Employee record linked to this user & organization
                $idNumber = 'EMP-' . $org->id . '-' . str_pad($counter, 4, '0', STR_PAD_LEFT);

                Employee::firstOrCreate(
                    ['organization_id' => $org->id, 'user_id' => $user->id],
                    [
                        'department_id' => $defaultDept->id,
                        'shift_id' => $defaultShift->id,
                        'name' => $staff['name'],
                        'id_number' => $idNumber,
                        'email' => $email,
                        'phone' => 0700077000,
                        'active' => true,
                    ]
                );

                $counter++;
            }
        }

        // create token to be used for APis for the test user (optional)
        $testUser->createToken('Api Token')->plainTextToken;
    }
}
