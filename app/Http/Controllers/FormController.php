<?php

namespace App\Http\Controllers;

use App\Services\AI\FormWorkflowRunner;
use App\Services\AI\FullAiAutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
<<<<<<< HEAD
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
=======
>>>>>>> parent of cd712ea (First)

class FormController extends Controller
{
    public function index()
    {
        $drinksGroups = [
            'tabozinah' => [
                'label' => 'Tabozinah Drinks',
                'items' => [],
            ],
            'soda' => [
                'label' => 'Soda Drinks',
                'items' => [],
            ],
            'others' => [
                'label' => 'Other Drinks',
                'items' => [],
            ],
        ];
        $hotDrinksGroups = [
            'tea' => [
                'label' => 'Tea Drinks',
                'items' => [],
            ],
            'coffee' => [
                'label' => 'Coffee Drinks',
                'items' => [],
            ],
            'others' => [
                'label' => 'Other Hot Drinks',
                'items' => [],
            ],
        ];
        $naturalJuicesGroups = [
            'natural' => [
                'label' => 'Natural Juices',
                'items' => [],
            ],
        ];
        $sweetsGroups = [
            'sweets' => [
                'label' => 'Sweets',
                'items' => [],
            ],
        ];
        $burgerGroups = [
            'burgers' => [
                'label' => 'Burgers',
                'items' => [],
            ],
        ];
        $sandwichGroups = [
            'baguette' => [
                'label' => 'Baguette Sandwiches',
                'items' => [],
            ],
            'ciabatta' => [
                'label' => 'Ciabatta Sandwiches',
                'items' => [],
            ],
            'rolls' => [
                'label' => 'Roll Sandwiches',
                'items' => [],
            ],
            'others' => [
                'label' => 'Other Sandwiches',
                'items' => [],
            ],
        ];
        $ingredientsGroups = [
            'cheese' => [
                'label' => 'Cheese',
                'items' => [],
            ],
            'vegetables' => [
                'label' => 'Vegetables',
                'items' => [],
            ],
            'meat' => [
                'label' => 'Meat',
                'items' => [],
            ],
            'others' => [
                'label' => 'Other Ingredients',
                'items' => [],
            ],
        ];
        $pastaMealsGroups = [
            'fettuccine' => [
                'label' => 'Fettuccine',
                'items' => [],
            ],
            'tortellini' => [
                'label' => 'Tortellini',
                'items' => [],
            ],
            'ravioli' => [
                'label' => 'Ravioli',
                'items' => [],
            ],
            'spaghetti' => [
                'label' => 'Spaghetti',
                'items' => [],
            ],
            'penne' => [
                'label' => 'Penne',
                'items' => [],
            ],
            'others' => [
                'label' => 'Other Pasta Meals',
                'items' => [],
            ],
        ];
        $sodaKeywords = [
            'cola',
            'soda',
            'sprite',
            'spritezero',
            'sprite zero',
            'fanta',
            'redbull',
            'blu',
            'day',
            'xl',
            'xlten',
            'xl ten',
            'fuzetea',
            'fuze tea',
            'fuze',
            'lemonade',
            'pepsi',
            '7up',
            'dr pepper',
        ];
        $teaKeywords = [
            'tea',
            'green',
            'herbal',
            'mint',
            'earl grey',
            'chamomile',
            'black tea',
            'chai',
        ];
        $coffeeKeywords = [
            'coffee',
            'espresso',
            'americano',
            'latte',
            'cappuccino',
            'mocha',
            'macchiato',
            'nescafe',
            'turkish',
        ];
        $pastaFettuccineKeywords = [
            'fettuccine',
            'fettucine',
            'alfredo',
        ];
        $pastaTortelliniKeywords = [
            'tortellini',
        ];
        $pastaRavioliKeywords = [
            'ravioli',
        ];
        $pastaSpaghettiKeywords = [
            'spaghetti',
        ];
        $pastaPenneKeywords = [
            'penne',
        ];
        $sandwichBaguetteKeywords = [
            'baguette',
        ];
        $sandwichCiabattaKeywords = [
            'ciabatta',
        ];
        $sandwichRollKeywords = [
            'roll',
            'rolls',
        ];
        $ingredientCheeseKeywords = [
            'cheese',
            'feta',
            'brie',
            'gouda',
            'halloumi',
            'mozzarella',
            'yellow_cheese',
        ];
        $ingredientMeatKeywords = [
            'beef',
            'pastrami',
            'pepperoni',
            'shoulder',
            'meat',
        ];
        $ingredientVegetableKeywords = [
            'avocado',
            'sprouts',
            'broccoli',
            'onion',
            'potato',
            'tomato',
            'eggplant',
            'cucumber',
            'lettuce',
            'kale',
            'basil',
            'parsley',
            'mint',
            'cabbage',
            'corn',
            'carrot',
            'beetroot',
            'radish',
            'mushroom',
            'arugula',
            'quinoa',
        ];
        $drinksDirectory = public_path('ColdDrinks');
        $hotDrinksDirectory = public_path('HotDrinks');
        $naturalJuicesDirectory = public_path('NaturalJuice');
        $sweetsDirectory = public_path('Sweets');
        $pastaDirectory = public_path('pasta');
        $burgerDirectory = public_path('burger');
        $sandwichesDirectory = public_path('sandwiches');
        $ingredientsDirectory = public_path('ingredients');
        $excludedFiles = [
            '7290008757201.jpg.webp',
        ];

        if (File::exists($drinksDirectory)) {
            $files = File::files($drinksDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();

                if (in_array($filename, $excludedFiles, true)) {
                    continue;
                }
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $normalized = strtolower($name);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));
                if (str_ends_with($normalized, 'big')) {
                    $trimmed = substr($name, 0, -3);
                    $label = trim(ucwords(str_replace(['-', '_'], ' ', $trimmed))) . ' Big';
                }

                // Build URL from the actual path to avoid case-sensitivity issues on Linux hosts
                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                $drink = [
                    'name' => $label,
                    'key' => strtolower($name),
                    'filename' => $filename,
                    'url' => asset($relativePath),
                ];

                if (str_contains($normalized, 'tabozinah')) {
                    $groupKey = 'tabozinah';
                } elseif ($this->stringContainsAny($normalized, $sodaKeywords)) {
                    $groupKey = 'soda';
                } else {
                    $groupKey = 'others';
                }

                $drinksGroups[$groupKey]['items'][] = $drink;
            }

            foreach ($drinksGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
            unset($group);
        }

        if (File::exists($hotDrinksDirectory)) {
            $files = File::files($hotDrinksDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $normalized = strtolower($name);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));

                $drink = [
                    'name' => $label,
                    'key' => strtolower($name),
                    'filename' => $filename,
                    'url' => asset('HotDrinks/' . $filename),
                ];

                if ($this->stringContainsAny($normalized, $teaKeywords)) {
                    $groupKey = 'tea';
                } elseif ($this->stringContainsAny($normalized, $coffeeKeywords)) {
                    $groupKey = 'coffee';
                } else {
                    $groupKey = 'others';
                }

                $hotDrinksGroups[$groupKey]['items'][] = $drink;
            }

            foreach ($hotDrinksGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
            unset($group);
        }

        if (File::exists($naturalJuicesDirectory)) {
            $files = File::files($naturalJuicesDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));
                $nameAr = $this->translateJuiceNameToArabic($label);

                $juice = [
                    'name' => $label,
                    'name_ar' => $nameAr,
                    'key' => strtolower($name),
                    'filename' => $filename,
                    'url' => asset('NaturalJuice/' . $filename),
                ];

                $naturalJuicesGroups['natural']['items'][] = $juice;
            }

            foreach ($naturalJuicesGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
            unset($group);
        }

        if (File::exists($sweetsDirectory)) {
            $files = File::files($sweetsDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));
                
                // Get the actual directory name from the file path to handle case sensitivity
                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath); // Normalize path separators

                $sweet = [
                    'name' => $label,
                    'key' => strtolower($name),
                    'filename' => $filename,
                    'url' => asset('Sweets/' . $filename),
                ];

                $sweetsGroups['sweets']['items'][] = $sweet;
            }

            foreach ($sweetsGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
            unset($group);
        }

        if (File::exists($pastaDirectory)) {
            $files = File::files($pastaDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));

                // Use actual relative path to avoid case-sensitivity issues
                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                $normalized = strtolower($name);
                if ($this->stringContainsAny($normalized, $pastaFettuccineKeywords)) {
                    $groupKey = 'fettuccine';
                } elseif ($this->stringContainsAny($normalized, $pastaTortelliniKeywords)) {
                    $groupKey = 'tortellini';
                } elseif ($this->stringContainsAny($normalized, $pastaRavioliKeywords)) {
                    $groupKey = 'ravioli';
                } elseif ($this->stringContainsAny($normalized, $pastaSpaghettiKeywords)) {
                    $groupKey = 'spaghetti';
                } elseif ($this->stringContainsAny($normalized, $pastaPenneKeywords)) {
                    $groupKey = 'penne';
                } else {
                    $groupKey = 'others';
                }

                $pastaMealsGroups[$groupKey]['items'][] = [
                    'name' => $label,
                    'key' => strtolower($name),
                    'filename' => $filename,
                    'url' => asset($relativePath),
                ];
            }

            foreach ($pastaMealsGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
            unset($group);
        }

        if (File::exists($burgerDirectory)) {
            $files = File::files($burgerDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));

                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                $burgerGroups['burgers']['items'][] = [
                    'name' => $label,
                    'key' => strtolower($name),
                    'filename' => $filename,
                    'url' => asset($relativePath),
                ];
            }

            foreach ($burgerGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
            unset($group);
        }

        if (File::exists($sandwichesDirectory)) {
            $files = File::files($sandwichesDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));

                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                $normalized = strtolower($name);
                if ($this->stringContainsAny($normalized, $sandwichBaguetteKeywords)) {
                    $groupKey = 'baguette';
                } elseif ($this->stringContainsAny($normalized, $sandwichCiabattaKeywords)) {
                    $groupKey = 'ciabatta';
                } elseif ($this->stringContainsAny($normalized, $sandwichRollKeywords)) {
                    $groupKey = 'rolls';
                } else {
                    $groupKey = 'others';
                }

                $sandwichGroups[$groupKey]['items'][] = [
                    'name' => $label,
                    'key' => strtolower($name),
                    'filename' => $filename,
                    'url' => asset($relativePath),
                ];
            }

            foreach ($sandwichGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
            unset($group);
        }

        if (File::exists($ingredientsDirectory)) {
            $files = File::files($ingredientsDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));

                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                $normalized = strtolower($name);
                if ($this->stringContainsAny($normalized, $ingredientCheeseKeywords)) {
                    $groupKey = 'cheese';
                } elseif ($this->stringContainsAny($normalized, $ingredientMeatKeywords)) {
                    $groupKey = 'meat';
                } elseif ($this->stringContainsAny($normalized, $ingredientVegetableKeywords)) {
                    $groupKey = 'vegetables';
                } else {
                    $groupKey = 'others';
                }

                $ingredientsGroups[$groupKey]['items'][] = [
                    'name' => $label,
                    'key' => strtolower($name),
                    'filename' => $filename,
                    'url' => asset($relativePath),
                ];
            }

            foreach ($ingredientsGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
            unset($group);
        }

        return view('form', [
            'drinksGroups' => array_filter($drinksGroups, function ($group) {
                return !empty($group['items']);
            }),
            'hotDrinksGroups' => array_filter($hotDrinksGroups, function ($group) {
                return !empty($group['items']);
            }),
            'naturalJuicesGroups' => array_filter($naturalJuicesGroups, function ($group) {
                return !empty($group['items']);
            }),
            'sweetsGroups' => array_filter($sweetsGroups, function ($group) {
                return !empty($group['items']);
            }),
            'pastaMealsGroups' => array_filter($pastaMealsGroups, function ($group) {
                return !empty($group['items']);
            }),
            'burgerGroups' => array_filter($burgerGroups, function ($group) {
                return !empty($group['items']);
            }),
            'sandwichGroups' => array_filter($sandwichGroups, function ($group) {
                return !empty($group['items']);
            }),
            'ingredientsGroups' => array_filter($ingredientsGroups, function ($group) {
                return !empty($group['items']);
            }),
            'methodTypes' => FormWorkflowRunner::methodTypes(),
        ]);
    }

    public function submit(Request $request)
    {
        // For heavy AI flows, give PHP more time than the default 30s
        try {
            if (in_array($request->input('method_type'), ['Meal Store With AI Images', 'Category Store With AI Image'], true)) {
                @set_time_limit(300);
            }
        } catch (\Throwable $e) {
            // ignore if we cannot change time limit
        }

        // Validate the form data
        $validated = $request->validate([
<<<<<<< HEAD
            'method_type' => ['required', Rule::in(FormWorkflowRunner::methodTypes())],
            'subdomain' => 'required|string|max:64',
            'username' => 'required|string|max:255',
            'password' => 'required|string',
            'environment' => 'nullable|string|in:dev,rest',
            'description' => 'nullable|string',
            'translate_names' => 'nullable|boolean',
        ]);

        $subdomain = KamanUrl::normalizeSubdomain($validated['subdomain']);
        $username = trim($validated['username']);
        $environment = KamanUrl::tldFromEnvironment($validated['environment'] ?? null);

=======
            'method_type' => 'required|in:Meal Store,Meal Store With AI Images,Category Store,Category Store With AI Image,Category Ingredients Store,Ingredients Store,Drinks Store,Natural Juices Store,Ingredients Images Store,Custom Images Meals Store,Custom Image Named',
            'restaurant_name' => 'required|string|max:255',
            'password' => 'required|string',
            'description' => 'nullable|string',
            'category_name_en' => 'nullable|string|max:255',
            'drinks_payload' => 'nullable|string',
            'folder_name' => 'nullable|string|max:255|required_if:method_type,Custom Images Meals Store',
            'meal_images' => 'nullable|array|max:50|required_if:method_type,Custom Images Meals Store',
            'meal_images.*' => 'nullable|image|max:10240', // 10MB max per image
            'folder_paths' => 'nullable|array|required_if:method_type,Custom Image Named',
            'folder_paths.*' => 'nullable|string|max:500',
            'folder_files' => 'nullable|array|required_if:method_type,Custom Image Named',
            'folder_files.*' => 'nullable|image|max:10240', // 10MB max per image
            'meal_style_image' => 'nullable|image|max:10240',
            'category_logo' => 'nullable|image|max:10240|required_if:method_type,Category Store With AI Image',
        ]);

        // Image-based flows use the drinks_payload builder
        if (in_array($validated['method_type'] ?? '', ['Drinks Store', 'Natural Juices Store', 'Ingredients Images Store'], true)) {
            $drinksPayload = $request->input('drinks_payload');
            $drinksSelection = json_decode($drinksPayload ?? '[]', true);

            if (!is_array($drinksSelection) || empty($drinksSelection)) {
                // Context-aware error message based on method type
                $itemName = $validated['method_type'] === 'Ingredients Images Store' ? 'ingredient' : 'item';
                
                return back()
                    ->withErrors(['drinks_payload' => "Please select at least one {$itemName} and provide its price."])
                    ->withInput();
            }

            $lines = [];

            foreach ($drinksSelection as $drink) {
                if (
                    !isset($drink['name'], $drink['price']) ||
                    trim((string) $drink['name']) === '' ||
                    trim((string) $drink['price']) === '' ||
                    !is_numeric($drink['price']) ||
                    (float) $drink['price'] < 0
                ) {
                    // Context-aware error message based on method type
                    $itemName = $validated['method_type'] === 'Ingredients Images Store' ? 'ingredient' : 'item';
                    
                    return back()
                        ->withErrors(['drinks_payload' => "Each selected {$itemName} must include a valid price."])
                        ->withInput();
                }

                $name = trim((string) $drink['name']);
                $price = number_format((float) $drink['price'], 2, '.', '');
                $lines[] = strtolower($drink['key'] ?? $name) . ' : ' . $price;
            }

            // Use category_name_en if provided, otherwise fall back to default title
            $categoryNameEn = !empty($validated['category_name_en']) ? trim($validated['category_name_en']) : null;
            
            if ($validated['method_type'] === 'Natural Juices Store') {
                $title = $categoryNameEn ?? 'عصائر طبيعية';
                // For Natural Juices, include name_ar in the format: key : price : name_ar
                $juiceLines = [];
                foreach ($drinksSelection as $juice) {
                    $key = strtolower($juice['key'] ?? $juice['name']);
                    $price = number_format((float) $juice['price'], 2, '.', '');
                    $nameAr = $juice['name_ar'] ?? '';
                    $juiceLines[] = $key . ' : ' . $price . ($nameAr ? ' : ' . $nameAr : '');
                }
                $validated['description'] = $title . " : {{\n" . implode("\n", $juiceLines) . "\n}}";
            } elseif ($validated['method_type'] === 'Ingredients Images Store') {
                $title = $categoryNameEn ?? 'مكونات';
                $validated['description'] = $title . " : {{\n" . implode("\n", $lines) . "\n}}";
            } else {
                $title = $categoryNameEn ?? 'مشروبات باردة';
                $validated['description'] = $title . " : {{\n" . implode("\n", $lines) . "\n}}";
            }
        }

        // Handle Custom Image Named - upload folder with exact names to public path
        $folderImageNames = [];
        if (($validated['method_type'] ?? null) === 'Custom Image Named') {
            $paths = $request->input('folder_paths', []);
            $files = $request->file('folder_files', []);
            if (empty($paths) || empty($files) || count($paths) !== count($files)) {
                $response = ['success' => false, 'error' => 'Invalid folder upload.'];
                return $request->ajax() || $request->wantsJson()
                    ? response()->json($response, 400)
                    : back()->withErrors(['folder_upload' => 'Invalid folder upload.'])->withInput();
            }
            $rootFolder = null;
            foreach ($paths as $i => $path) {
                $path = trim((string) str_replace('\\', '/', $path));
                if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
                    continue;
                }
                $segments = explode('/', $path);
                $currentRoot = $segments[0] ?? '';
                if ($currentRoot === '') {
                    continue;
                }
                if ($rootFolder === null) {
                    $rootFolder = $currentRoot;
                }
                if ($currentRoot !== $rootFolder || !isset($files[$i]) || !$files[$i]->isValid()) {
                    continue;
                }
                $targetPath = public_path($path);
                $targetDir = dirname($targetPath);
                if (!File::exists($targetDir)) {
                    File::makeDirectory($targetDir, 0755, true);
                }
                $filename = basename($path);
                $files[$i]->move($targetDir, $filename);
                $folderImageNames[] = $path;
            }
            if (empty($folderImageNames)) {
                $response = ['success' => false, 'error' => 'No valid images could be uploaded.'];
                return $request->ajax() || $request->wantsJson()
                    ? response()->json($response, 400)
                    : back()->withErrors(['folder_upload' => 'No valid images could be uploaded.'])->withInput();
            }
        }

        // Optional style image for Meal Store With AI Images
        $mealStyleImagePath = null;
        if ($validated['method_type'] === 'Meal Store With AI Images' && $request->hasFile('meal_style_image')) {
            $file = $request->file('meal_style_image');
            if ($file && $file->isValid()) {
                $targetDirectory = storage_path('app/meal-style');
                if (!File::exists($targetDirectory)) {
                    File::makeDirectory($targetDirectory, 0755, true);
                }
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move($targetDirectory, $filename);
                $mealStyleImagePath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
            }
        }

        // Required logo image for Category Store With AI Image
        $categoryLogoPath = null;
        if ($validated['method_type'] === 'Category Store With AI Image' && $request->hasFile('category_logo')) {
            $file = $request->file('category_logo');
            if ($file && $file->isValid()) {
                $targetDirectory = storage_path('app/category-logos');
                if (!File::exists($targetDirectory)) {
                    File::makeDirectory($targetDirectory, 0755, true);
                }
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move($targetDirectory, $filename);
                $categoryLogoPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
            }
        }

        // Handle Custom Images Meals Store - store images in public folder
        $imageNames = [];
        if ($validated['method_type'] === 'Custom Images Meals Store') {
            $folderName = $validated['folder_name'];
            
            // Sanitize folder name - only allow alphanumeric, dash, and underscore
            $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $folderName);
            $folderName = trim($folderName, '-_');
            
            if (empty($folderName)) {
                return back()
                    ->withErrors(['folder_name' => 'Folder name cannot be empty or contain only invalid characters.'])
                    ->withInput();
            }
            
            // Create directory in public folder
            $targetDirectory = public_path($folderName);
            
            if (!File::exists($targetDirectory)) {
                File::makeDirectory($targetDirectory, 0755, true);
            }
            
            // Store uploaded images
            if ($request->hasFile('meal_images')) {
                $uploadedFiles = $request->file('meal_images');
                
                foreach ($uploadedFiles as $file) {
                    if ($file->isValid()) {
                        // Generate unique filename to avoid conflicts
                        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                        $file->move($targetDirectory, $filename);
                        $imageNames[] = $filename;
                    }
                }
            }
        }

        // Prepare webhook payload
>>>>>>> parent of cd712ea (First)
        $payload = [
            'method_type' => $validated['method_type'],
            'subdomain' => $subdomain,
            'restaurant_name' => $subdomain,
            'username' => $username,
            'environment' => $environment,
            'password' => $validated['password'],
<<<<<<< HEAD
            'description' => trim((string) ($validated['description'] ?? '')),
            'translate_names' => $request->boolean('translate_names', true),
=======
            'description' => $validated['description'] ?? '',
>>>>>>> parent of cd712ea (First)
            'submitted_at' => now()->toIso8601String(),
            'ip_address' => $request->ip(),
        ];
        
        // Add category_name_en in the requested format if filled
        if (!empty($validated['category_name_en'])) {
            $payload['category_name_en'] = 'Category name (english) field value : ' . $validated['category_name_en'];
        }

        // Pass style image path to Meal Store With AI Images workflow
        if ($validated['method_type'] === 'Meal Store With AI Images' && $mealStyleImagePath) {
            $payload['meal_style_image_path'] = $mealStyleImagePath;
        }

        // Pass logo path to Category Store With AI Image workflow
        if ($validated['method_type'] === 'Category Store With AI Image' && $categoryLogoPath) {
            $payload['category_logo_path'] = $categoryLogoPath;
        }
        
        // Add folder_name and image_names for Custom Images Meals Store
        if ($validated['method_type'] === 'Custom Images Meals Store') {
            if (!empty($validated['folder_name'])) {
                $payload['folder_name'] = $validated['folder_name'];
            }
            if (!empty($imageNames)) {
                $payload['image_names'] = $imageNames;
            }
        }

        // Add folder structure for Custom Image Named
        if ($validated['method_type'] === 'Custom Image Named' && !empty($folderImageNames)) {
            $firstPath = $folderImageNames[0];
            $payload['folder_name'] = explode('/', $firstPath)[0] ?? $firstPath;
            $payload['image_paths'] = $folderImageNames;
        }

        // Add drinks selection and image directory for image-based workflows
        if (in_array($validated['method_type'] ?? '', ['Drinks Store', 'Natural Juices Store', 'Ingredients Images Store'], true)) {
            $payload['drinks_selection'] = json_decode($request->input('drinks_payload', '[]'), true) ?: [];
            $payload['drinks_directory'] = $validated['method_type'] === 'Natural Juices Store'
                ? 'NaturalJuice'
                : ($validated['method_type'] === 'Ingredients Images Store' ? 'ingredients' : 'ColdDrinks');
        }

        // Run AI workflow for this form path
        $workflowRunner = app(FormWorkflowRunner::class);

        // Live debug: stream progress as Server-Sent Events
        if (($request->ajax() || $request->wantsJson()) && $request->header('X-Live-Debug') === '1') {
            return response()->stream(function () use ($workflowRunner, $validated, $payload) {
                set_time_limit(600);
                $emit = function (array $event) {
                    echo 'data: ' . json_encode($event) . "\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                };

                $onProgress = function (string $step, string $message, array $data = []) use ($emit) {
                    $emit([
                        'event' => 'step',
                        'step' => $step,
                        'message' => $message,
                        'data' => $data,
                        'timestamp' => now()->toIso8601String(),
                    ]);
                };

                try {
                    $result = $workflowRunner->run($validated['method_type'], $payload, $onProgress);
                } catch (\Throwable $e) {
                    $emit([
                        'event' => 'done',
                        'success' => false,
                        'error' => $e->getMessage(),
                        'method_type' => $validated['method_type'],
                        'payload' => $payload,
                        'result' => ['success' => false, 'error' => $e->getMessage()],
                        'timestamp' => now()->toIso8601String(),
                    ]);
                    return;
                }

                $debugData = [
                    'method_type' => $validated['method_type'],
                    'payload' => $payload,
                    'result' => $result,
                    'timestamp' => now()->toIso8601String(),
                ];

                $redirect = back();
                if ($result['success'] ?? false) {
                    session()->flash('success', $result['message'] ?? 'Form submitted successfully!');
                    session()->flash('workflow_debug', $debugData);
                } else {
                    session()->flash('warning', $result['error'] ?? 'Workflow failed.');
                    session()->flash('workflow_debug', $debugData);
                }

                $emit([
                    'event' => 'done',
                    'success' => $result['success'] ?? false,
                    'method_type' => $validated['method_type'],
                    'payload' => $payload,
                    'result' => $result,
                    'timestamp' => $debugData['timestamp'],
                    'redirect' => $redirect->getTargetUrl(),
                ]);
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        }

        $result = $workflowRunner->run($validated['method_type'], $payload);

        $debugData = [
            'method_type' => $validated['method_type'],
            'payload' => $payload,
            'result' => $result,
            'timestamp' => now()->toIso8601String(),
        ];

        $redirect = back();

        if ($result['success']) {
            $redirect->with('success', $result['message'] ?? 'Form submitted successfully! AI workflow completed.')
                ->with('workflow_debug', $debugData);
            if ($request->ajax() || $request->wantsJson()) {
                session()->flash('success', $result['message'] ?? 'Form submitted successfully! AI workflow completed.');
                session()->flash('workflow_debug', $debugData);
                return response()->json([
                    'success' => true,
                    'redirect' => $redirect->getTargetUrl(),
                    'workflow_debug' => $debugData,
                ]);
            }
            return $redirect;
        }

        $errorMessage = $result['error'] ?? 'AI workflow failed.';
        $redirect->with('warning', $errorMessage)
            ->with('workflow_debug', $debugData)
            ->withInput();
        if ($request->ajax() || $request->wantsJson()) {
            session()->flash('warning', $errorMessage);
            session()->flash('workflow_debug', $debugData);
            session()->flashInput($request->input());
            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'redirect' => $redirect->getTargetUrl(),
                'workflow_debug' => $debugData,
            ]);
        }
        return $redirect;
    }

    private function stringContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function translateJuiceNameToArabic(string $englishName): string
    {
        $lowerName = strtolower($englishName);
        $words = explode(' ', $lowerName);
        $arabicWords = [];

        // Translation mappings
        $translations = [
            'carrot' => 'جزر',
            'lemon' => 'ليمون',
            'orange' => 'برتقال',
            'pomegranate' => 'رمان',
            'juice' => 'عصير',
            'jug' => 'بريق',
        ];

        foreach ($words as $word) {
            $word = trim($word);
            if (isset($translations[$word])) {
                $arabicWords[] = $translations[$word];
            } else {
                // If not found in translations, keep the word as is (for unknown words)
                if ($word !== '') {
                    $arabicWords[] = $word;
                }
            }
        }

        return implode(' ', $arabicWords);
    }

    /**
     * Check whether a Kaman token is already stored for this subdomain (session/cache).
     */
    public function checkFullAiAuth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subdomain' => 'required|string|max:64',
            'environment' => 'nullable|string|in:dev,rest',
        ]);

        $subdomain = KamanUrl::normalizeSubdomain($validated['subdomain']);
        $tld = KamanUrl::tldFromEnvironment($validated['environment'] ?? null);
        $expectedBaseUrl = rtrim(KamanUrl::managerApi($subdomain, $tld), '/');

        $auth = $this->resolveFullAiAuth($request);

        if ($auth === null) {
            return response()->json([
                'success' => true,
                'authenticated' => false,
            ]);
        }

        $storedBaseUrl = rtrim($auth['base_url'], '/');

        if ($storedBaseUrl !== $expectedBaseUrl) {
            return response()->json([
                'success' => true,
                'authenticated' => false,
                'message' => 'No active session for this subdomain. Sign in with your username and password.',
            ]);
        }

        return $this->formJsonSuccess(
            'You are already signed in for this restaurant.',
            ['authenticated' => true]
        );
    }

    /**
     * Login to Kaman with subdomain, username, and password. Stores token in session for Full AI automation.
     */
    public function loginFullAi(Request $request)
    {
<<<<<<< HEAD
        try {
            $validated = $request->validate([
                'subdomain' => 'required|string|max:64',
                'username' => 'required|string|max:255',
                'password' => 'required|string|max:255',
                'environment' => 'nullable|string|in:dev,rest',
            ]);

            $subdomain = KamanUrl::normalizeSubdomain($validated['subdomain']);
            $username = trim($validated['username']);
            $tld = KamanUrl::tldFromEnvironment($validated['environment'] ?? null);
            $baseUrl = KamanUrl::managerApi($subdomain, $tld);

            Log::info('Full AI login attempt', [
                'subdomain' => $subdomain,
                'username' => $username,
                'tld' => $tld,
                'base_url' => $baseUrl,
            ]);

            try {
                $response = $this->kamanHttpClient(45)->post(KamanUrl::join($baseUrl, '/login'), [
                    'email' => KamanUrl::loginEmail($subdomain, $username),
                    'password' => $validated['password'],
                ]);
            } catch (ConnectionException|RequestException $e) {
                Log::error('Full AI login: Kaman API connection failed', [
                    'subdomain' => $subdomain,
                    'base_url' => $baseUrl,
                    'error' => $e->getMessage(),
                ]);

                return $this->formJsonError(
                    'Could not reach the restaurant API (' . $subdomain . '.kaman.' . KamanUrl::tld() . '). Check the subdomain, username, password, and server outbound HTTPS.',
                    503
                );
            }

            if (!$response->successful()) {
                $body = $response->json();
                $detail = is_array($body)
                    ? ($body['message'] ?? $body['error'] ?? $response->body())
                    : $response->body();
                $detail = is_string($detail) ? $detail : json_encode($detail);

                Log::warning('Full AI login failed', [
                    'status' => $response->status(),
                    'subdomain' => $subdomain,
                    'response' => $detail,
                ]);

                return $this->formJsonError(
                    'Login failed. Check subdomain, username, and password.',
                    401,
                    ['detail' => $detail]
                );
            }

            $data = $response->json();
            if (!is_array($data)) {
                Log::error('Full AI login: invalid JSON from Kaman', [
                    'subdomain' => $subdomain,
                    'body' => $response->body(),
                ]);

                return $this->formJsonError('Login response was invalid. Please try again.', 502);
            }

            $token = $data['token']
                ?? $data['access_token']
                ?? $data['data']['token']
                ?? (is_array($data['data'] ?? null) ? ($data['data']['access_token'] ?? null) : null);

            if ($token === null || $token === '') {
                Log::error('Full AI login: no token in response', [
                    'subdomain' => $subdomain,
                    'keys' => array_keys($data),
                ]);

                return $this->formJsonError('Login succeeded but no token was returned.', 502);
            }

            if (!$this->persistFullAiAuth($request, $token, $baseUrl)) {
                return $this->formJsonError(
                    'Login succeeded but credentials could not be stored. Ensure storage/framework/sessions is writable or set SESSION_DRIVER=file.',
                    503
                );
            }

            Log::info('Full AI login succeeded', ['subdomain' => $subdomain]);

            return $this->formJsonSuccess(
                'Token stored successfully. You can start Full AI automation.'
            );
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first()
                ?: 'Subdomain, username, and password are required.';

            return $this->formJsonError($message, 422, ['errors' => $e->errors()]);
        } catch (Throwable $e) {
            Log::error('Full AI login unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->formJsonError(
                'An unexpected error occurred during login. Please try again.',
                500
            );
        }
    }

    private function kamanHttpClient(int $timeoutSeconds = 30): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout($timeoutSeconds)->connectTimeout(20)->acceptJson();

        if (!config('services.kaman.ssl_verify', false)) {
=======
        $validated = $request->validate([
            'restaurant_name' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        $subdomain = $this->fullAiSubdomain($validated['restaurant_name']);
        $baseUrl = "https://{$subdomain}.kaman.rest";

        $http = Http::timeout(30)->acceptJson();
        if (!config('services.kaman.ssl_verify', true)) {
>>>>>>> parent of cd712ea (First)
            $http = $http->withoutVerifying();
        }

        $response = $http->post("{$baseUrl}/api/manager/login", [
            'email' => "{$subdomain}@kaman.rest",
            'password' => $validated['password'],
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? $body['error'] ?? $response->body()) : $response->body();
            $message = is_string($message) ? $message : json_encode($message);

            Log::warning('Full AI login failed', ['status' => $response->status(), 'response' => $message]);

            return response()->json([
                'success' => false,
                'error' => 'Login failed. Check restaurant name and password.',
                'detail' => $message,
            ], 401);
        }

        $data = $response->json();
        $token = $data['token'] ?? $data['access_token'] ?? $data['data']['token'] ?? null;

        if ($token === null || $token === '') {
            return response()->json([
                'success' => false,
                'error' => 'Login succeeded but no token was returned.',
            ], 502);
        }

        session([
            'full_ai_kaman_token' => $token,
            'full_ai_kaman_base_url' => $baseUrl,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Token stored successfully. You can start Full AI automation.',
        ]);
    }

    private function fullAiSubdomain(string $name): string
    {
        $subdomain = strtolower(trim($name));
        $subdomain = preg_replace('/[^a-z0-9\-]/', '-', $subdomain);
        $subdomain = trim($subdomain, '-');
        $subdomain = (string) preg_replace('/-+/', '-', $subdomain);

        return $subdomain !== '' ? $subdomain : 'default';
    }

    /**
     * Execute one Full AI step by proxying the request to Kaman using the stored token.
     */
    public function executeFullAiStep(Request $request)
    {
        $validated = $request->validate([
            'method' => 'required|string|in:GET,POST,PUT,PATCH,DELETE',
            'path' => 'required|string|max:500',
            'body' => 'nullable|array',
        ]);

        $token = session('full_ai_kaman_token');
        $baseUrl = rtrim((string) session('full_ai_kaman_base_url', ''), '/');

        if ($token === null || $token === '' || $baseUrl === '') {
            return response()->json([
                'success' => false,
                'error' => 'Not logged in. Use the Login button with your restaurant name and password first.',
            ], 401);
        }

        $path = '/' . ltrim($validated['path'], '/');
        $url = $baseUrl . $path;
        $method = strtoupper($validated['method']);
        $body = $validated['body'] ?? [];

        $http = Http::timeout(60)->acceptJson()->withToken($token);
        if (!config('services.kaman.ssl_verify', true)) {
            $http = $http->withoutVerifying();
        }

        try {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                $response = $http->{$method === 'HEAD' ? 'head' : 'get'}($url);
                return response()->json([
                    'success' => $response->successful(),
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);
            }

            // Kaman expects one category per POST; Full AI sends an array of categories.
            if ($path === '/api/manager/categories' && $this->isSequentialArray($body)) {
                $results = ['created' => [], 'failed' => []];
                foreach ($body as $index => $category) {
                    if (!is_array($category)) {
                        continue;
                    }
                    $imageRelative = $category['image_relative_path'] ?? null;
                    $single = [
                        'name_en' => (string) ($category['name_en'] ?? $category['name'] ?? 'Category'),
                        'name_ar' => (string) ($category['name_ar'] ?? $category['name_en'] ?? $category['name'] ?? 'Category'),
                        'name_he' => (string) ($category['name_he'] ?? $category['name_en'] ?? $category['name'] ?? 'Category'),
                        'position' => (int) ($category['position'] ?? $index + 1),
                    ];
                    if ($single['name_en'] === '') {
                        $single['name_en'] = $single['name_ar'] = $single['name_he'] = 'Category ' . ($index + 1);
                    }
                    $imagePath = null;
                    if (is_string($imageRelative) && $imageRelative !== '') {
                        $candidate = public_path($imageRelative);
                        if (File::exists($candidate)) {
                            $imagePath = $candidate;
                        }
                    }
                    if ($imagePath) {
                        $categoryHttp = $http->attach('image', File::get($imagePath), basename($imagePath));
                        $response = $categoryHttp->post($url, $single);
                    } else {
                        $response = $http->post($url, $single);
                    }
                    if ($response->successful()) {
                        $data = $response->json();
                        $results['created'][] = ['index' => $index, 'id' => $data['data']['id'] ?? $data['id'] ?? $data['category']['id'] ?? null];
                    } else {
                        $results['failed'][] = ['index' => $index, 'error' => $response->json('message') ?? $response->body()];
                    }
                }
                return response()->json([
                    'success' => count($results['failed']) === 0,
                    'status' => count($results['created']) > 0 ? 200 : 422,
                    'body' => $results,
                ]);
            }

            // Kaman expects one item per POST with category_id; Full AI sends an array with category name.
            if ($path === '/api/manager/items' && $this->isSequentialArray($body)) {
                $categoriesResponse = $http->get($baseUrl . '/api/manager/categories');
                $categoryIdMap = [];
                if ($categoriesResponse->successful()) {
                    $data = $categoriesResponse->json();
                    $list = $data['data'] ?? $data['categories'] ?? $data;
                    if (is_array($list)) {
                        foreach ($list as $cat) {
                            $id = $cat['id'] ?? $cat['category_id'] ?? null;
                            if ($id === null) {
                                continue;
                            }
                            $nameEn = trim((string) ($cat['name_en'] ?? $cat['name'] ?? ''));
                            $nameAr = trim((string) ($cat['name_ar'] ?? $cat['name'] ?? ''));
                            if ($nameEn !== '') {
                                $categoryIdMap[$nameEn] = $id;
                            }
                            if ($nameAr !== '') {
                                $categoryIdMap[$nameAr] = $id;
                            }
                        }
                    }
                }
                $results = ['created' => [], 'failed' => []];
                foreach ($body as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $categoryName = trim((string) ($item['category'] ?? ''));
                    $categoryId = $categoryIdMap[$categoryName] ?? null;
                    if ($categoryId === null && $categoryName !== '') {
                        $categoryId = $categoryIdMap[mb_strtolower($categoryName)] ?? null;
                    }
                    if ($categoryId === null) {
                        $categoryId = (string) (reset($categoryIdMap) ?: '');
                    }
                    $single = [
                        'name_en' => (string) ($item['name_en'] ?? $item['name'] ?? 'Item'),
                        'name_ar' => (string) ($item['name_ar'] ?? $item['name_en'] ?? $item['name'] ?? 'Item'),
                        'name_he' => (string) ($item['name_he'] ?? $item['name_en'] ?? $item['name'] ?? 'Item'),
                        'price' => (string) ($item['price'] ?? '0.00'),
                        'category_id' => $categoryId,
                        'description_ar' => (string) ($item['description_ar'] ?? ''),
                        'description_en' => (string) ($item['description_en'] ?? ''),
                        'description_he' => (string) ($item['description_he'] ?? ''),
                    ];
                    if ($single['name_en'] === '') {
                        $single['name_en'] = $single['name_ar'] = $single['name_he'] = 'Item';
                    }
                    $response = $http->post($url, $single);
                    if ($response->successful()) {
                        $data = $response->json();
                        $results['created'][] = ['index' => $index, 'id' => $data['data']['id'] ?? $data['id'] ?? null];
                    } else {
                        $results['failed'][] = ['index' => $index, 'error' => $response->json('message') ?? $response->body()];
                    }
                }
                return response()->json([
                    'success' => count($results['failed']) === 0,
                    'status' => count($results['created']) > 0 ? 200 : 422,
                    'body' => $results,
                ]);
            }

            $response = $http->{strtolower($method)}($url, $body);
        } catch (\Throwable $e) {
            Log::error('Full AI execute step failed', ['url' => $url, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'status' => 0,
                'error' => $e->getMessage(),
                'body' => null,
            ], 502);
        }

        $status = $response->status();
        $responseBody = $response->json() ?? $response->body();

        return response()->json([
            'success' => $response->successful(),
            'status' => $status,
            'body' => $responseBody,
        ]);
    }

    private function isSequentialArray(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Simple chat endpoint for the Full AI assistant.
     */
    public function chatFullAutomation(Request $request, FullAiAutomationService $service)
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string',
        ]);

        try {
            $reply = $service->chatAssistant($validated['messages']);
        } catch (\Throwable $e) {
            Log::error('Full AI chat failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Assistant is temporarily unavailable. Try again in a moment.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'reply' => $reply,
        ]);
    }

    public function startFullAutomation(Request $request, FullAiAutomationService $service)
    {
        $validated = $request->validate([
            'subdomain' => 'required|string|max:64',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'environment' => 'nullable|string|in:dev,rest',
            'description' => 'nullable|string',
            'agent_instructions' => 'nullable|string|max:2000',
            'logo' => 'nullable|image|max:5120',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'nullable|file|max:20480',
        ]);

        $hasDescription = isset($validated['description']) && trim($validated['description']) !== '';
        $hasFiles = $request->hasFile('attachments');

        if (!$hasDescription && !$hasFiles) {
            return response()->json([
                'success' => false,
                'error' => 'Provide menu text or upload at least one PDF/image.',
            ], 422);
        }

        $sessionId = (string) Str::uuid();
        $subdomain = KamanUrl::normalizeSubdomain($validated['subdomain']);
        $payload = [
            'subdomain' => $subdomain,
            'restaurant_name' => $subdomain,
            'username' => trim($validated['username']),
            'environment' => KamanUrl::tldFromEnvironment($validated['environment'] ?? null),
            'password' => $validated['password'],
            'description' => $validated['description'] ?? '',
            'agent_instructions' => $validated['agent_instructions'] ?? '',
            'attachments' => [],
        ];

        $targetDir = public_path('full-ai-sessions/' . $sessionId);
        if ($hasFiles || $request->hasFile('logo')) {
            if (!File::exists($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }
        }
        if ($hasFiles) {
            foreach ($request->file('attachments') as $file) {
                if (!$file->isValid()) {
                    continue;
                }
                $originalName = $file->getClientOriginalName();
                $slug = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
                $extension = $file->getClientOriginalExtension();
                $filename = time() . '_' . uniqid() . '_' . ($slug ?: 'menu') . '.' . $extension;
                $file->move($targetDir, $filename);
                $payload['attachments'][] = [
                    'name' => $originalName,
                    'path' => $targetDir . '/' . $filename,
                    'relative_path' => 'full-ai-sessions/' . $sessionId . '/' . $filename,
                    'mime' => $file->getClientMimeType(),
                ];
            }
        }
        if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
            $logoFile = $request->file('logo');
            $logoExt = $logoFile->getClientOriginalExtension() ?: 'png';
            $logoFilename = 'logo.' . $logoExt;
            $logoFile->move($targetDir, $logoFilename);
            $payload['logo_path'] = $targetDir . '/' . $logoFilename;
            $payload['logo_relative_path'] = 'full-ai-sessions/' . $sessionId . '/' . $logoFilename;
        }

        try {
            set_time_limit(300);
            $result = $service->start($payload, $sessionId);
        } catch (\Throwable $e) {
            Log::error('Full AI automation start failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'session_id' => $result['session_id'],
            'next_step' => $result['next_step'],
            'diagram' => $result['diagram'],
        ]);
    }

    public function approveFullAutomation(string $session, Request $request, FullAiAutomationService $service)
    {
        $request->validate([
            'approved' => 'nullable|boolean',
            'step_id' => 'nullable|string|max:255',
        ]);

        $approved = filter_var($request->input('approved', true), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $service->approve($session, $approved);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        return response()->json(array_merge(['success' => true], $result));
    }

    /**
     * Translate a pasta meal name into English, Arabic, and Hebrew variants.
     *
     * The English name is normalized from the given label, while specific
     * keywords like "rose" and "ravioli" are mapped to their Arabic/Hebrew
     * equivalents as requested.
     *
     * @param string $label
     * @return array{0:string,1:string,2:string} Returns [name_en, name_ar, name_he]
     */
    private function translatePastaNameToLanguages(string $label): array
    {
        $label = trim($label);
        if ($label === '') {
            return ['', '', ''];
        }

        // Normalize label (replace underscores with spaces, collapse whitespace)
        $normalized = preg_replace('/\s+/', ' ', str_replace('_', ' ', strtolower($label))) ?? '';
        
        // Multi-word phrases that should be translated as a unit
        $multiWordPhrases = [
            'aglio e olio' => ['Aglio e Olio', 'أجليو أوليو', 'אגליו אוליו'],
        ];
        
        // Replace multi-word phrases with placeholders, then translate word by word
        $phrasePlaceholders = [];
        $placeholderCounter = 0;
        foreach ($multiWordPhrases as $phrase => $translation) {
            if (str_contains($normalized, $phrase)) {
                $placeholder = '__PHRASE_' . $placeholderCounter . '__';
                $phrasePlaceholders[$placeholder] = $translation;
                $normalized = str_replace($phrase, $placeholder, $normalized);
                $placeholderCounter++;
            }
        }
        
        $words = explode(' ', $normalized);

        // Comprehensive translation dictionary for pasta-related terms
        $translations = [
            // Pasta types
            'fettuccine' => ['Fettuccine', 'فيتوتشيني', 'פטוציני'],
            'fettucine' => ['Fettuccine', 'فيتوتشيني', 'פטוציני'],
            'ravioli' => ['Ravioli', 'رافيولي', 'ראביולי'],
            'tortellini' => ['Tortellini', 'تورتيليني', 'טורטליני'],
            'spaghetti' => ['Spaghetti', 'سباغيتي', 'ספגטי'],
            'penne' => ['Penne', 'بيني', 'פנה'],
            'linguine' => ['Linguine', 'لينغويني', 'לינגוויני'],
            'lasagna' => ['Lasagna', 'لازانيا', 'לזניה'],
            'macaroni' => ['Macaroni', 'مكرونة', 'מקרוני'],
            'fusilli' => ['Fusilli', 'فوسيلي', 'פוסילי'],
            'rigatoni' => ['Rigatoni', 'ريجاتوني', 'ריגטוני'],
            'gnocchi' => ['Gnocchi', 'جنوكי', 'גנוקי'],
            
            // Sandwich types
            'baguette' => ['Baguette', 'باجيت', 'באגט'],
            'ciabatta' => ['Ciabatta', 'جبيتا', 'גאבטה'],
            'roll' => ['Roll', 'رول', 'רול'],
            'rolls' => ['Rolls', 'رول', 'רול'],
            'sandwich' => ['Sandwich', 'ساندويتش', 'כריך'],
            'sandwiches' => ['Sandwiches', 'ساندويتشات', 'כריכים'],
            
            // Sauces and preparations
            'alfredo' => ['Alfredo', 'ألفريدو', 'אלפרדו'],
            'rose' => ['Rose', 'روزيه', 'רוזה'],
            'carbonara' => ['Carbonara', 'كربونارا', 'קרבונרה'],
            'bolognese' => ['Bolognese', 'بولونيز', 'בולונז'],
            'marinara' => ['Marinara', 'مارينارا', 'מרינרה'],
            'pesto' => ['Pesto', 'بيستو', 'פסטו'],
            'arrabbiata' => ['Arrabbiata', 'أرابياتا', 'אראביאטה'],
            'aglio' => ['Aglio', 'أجليو', 'אגליו'],
            'olio' => ['Olio', 'أوليو', 'אוליו'],
            'aglio e olio' => ['Aglio e Olio', 'أجليو أوليو', 'אגליו אוליו'],
            
            // Ingredients
            'chicken' => ['Chicken', 'دجاج', 'עוף'],
            'breast' => ['Breast', 'صدر', 'חזה'],
            'meat' => ['Meat', 'لحم', 'בשר'],
            'beef' => ['Beef', 'لحم بقري', 'בשר בקר'],
            'kebab' => ['Kebab', 'كباب', 'קבב'],
            'schnitzel' => ['Schnitzel', 'شنيتسل', 'שניצל'],
            'entrecote' => ['Entrecote', 'أنتركوت', 'אנטרקוט'],
            'turkey' => ['Turkey', 'ديك رومي', 'הודו'],
            'ham' => ['Ham', 'لحم مقدد', 'נקניק'],
            'bacon' => ['Bacon', 'لحم مقدد', 'בייקון'],
            'salami' => ['Salami', 'سلامي', 'סלמי'],
            'pastrami' => ['Pastrami', 'باسترامي', 'פסטרמה'],
            'tuna' => ['Tuna', 'تونة', 'טונה'],
            'salmon' => ['Salmon', 'سلمون', 'סלמון'],
            'egg' => ['Egg', 'بيض', 'ביצה'],
            'eggs' => ['Eggs', 'بيض', 'ביצים'],
            'avocado' => ['Avocado', 'أفوكادو', 'אבוקדו'],
            'lettuce' => ['Lettuce', 'خس', 'חסה'],
            'pickle' => ['Pickle', 'مخلل', 'חמוצים'],
            'pickles' => ['Pickles', 'مخلل', 'חמוצים'],
            'mayonnaise' => ['Mayonnaise', 'مايونيز', 'מיונז'],
            'mustard' => ['Mustard', 'خردل', 'חרדל'],
            'honey' => ['Honey', 'عسل', 'דבש'],
            'honey mustard' => ['Honey Mustard', 'خردل بالعسل', 'חרדל דבש'],
            'shrimp' => ['Shrimp', 'جمبري', 'חסילונים'],
            'seafood' => ['Seafood', 'مأكولات بحرية', 'פירות ים'],
            'mushroom' => ['Mushroom', 'فطر', 'פטריות'],
            'mushrooms' => ['Mushrooms', 'فطر', 'פטריות'],
            'tomato' => ['Tomato', 'طماطم', 'עגבנייה'],
            'tomatoes' => ['Tomatoes', 'طماطم', 'עגבניות'],
            'cream' => ['Cream', 'شمينت', 'שמנת'],
            'chement' => ['Cream', 'شمينت', 'שמנת'],
            'cheese' => ['Cheese', 'جبن', 'גבינה'],
            'parmesan' => ['Parmesan', 'بارميزان', 'פארמזן'],
            'mozzarella' => ['Mozzarella', 'موتزاريلا', 'מוצרלה'],
            'basil' => ['Basil', 'ريحان', 'בזיליקום'],
            'garlic' => ['Garlic', 'ثوم', 'שום'],
            'onion' => ['Onion', 'بصل', 'בצל'],
            'pepper' => ['Pepper', 'فلفل', 'פלפל'],
            'peppers' => ['Peppers', 'فلفل', 'פלפלים'],
            'vegetable' => ['Vegetable', 'خضار', 'ירקות'],
            'vegetables' => ['Vegetables', 'خضار', 'ירקות'],
            'noodle' => ['Noodle', 'نودل', 'נודלס'],
            'noodles' => ['Noodles', 'نودل', 'נודלס'],
            'dish' => ['Dish', 'طبق', 'מנה'],
            'dishes' => ['Dishes', 'أطباق', 'מנות'],
            
            // Common words
            'pasta' => ['Pasta', 'باستا', 'פסטה'],
            'sauce' => ['Sauce', 'صلصة', 'רוטב'],
            'souce' => ['Sauce', 'صلصة', 'רוטב'], // Common misspelling
            'meal' => ['Meal', 'وجبة', 'מנה'],
            'meals' => ['Meals', 'وجبات', 'מנות'],
            'with' => ['With', 'مع', 'עם'],
            'and' => ['And', 'و', 'ו'],
            'e' => ['e', 'و', 'ו'], // Italian "e" (and) - used in phrases like "aglio e olio"
            'special' => ['Special', 'خاص', 'מיוחד'],
            'deluxe' => ['Deluxe', 'ديلوكس', 'דלוקס'],
            'classic' => ['Classic', 'كلاسيكי', 'קלאסי'],
            'traditional' => ['Traditional', 'تقليدي', 'מסורתי'],
        ];

        $enWords = [];
        $arWords = [];
        $heWords = [];

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }

            // Check if this is a multi-word phrase placeholder
            if (isset($phrasePlaceholders[$word])) {
                $translation = $phrasePlaceholders[$word];
                $enWords[] = $translation[0];
                $arWords[] = $translation[1];
                $heWords[] = $translation[2];
            }
            // Check if we have a translation for this word
            elseif (isset($translations[$word])) {
                $enWords[] = $translations[$word][0];
                $arWords[] = $translations[$word][1];
                $heWords[] = $translations[$word][2];
            } else {
                // Default: keep English word title-cased, and reuse it
                // for Arabic/Hebrew when no specific translation exists.
                $title = ucfirst($word);
                $enWords[] = $title;
                $arWords[] = $title;
                $heWords[] = $title;
            }
        }

        return [
            implode(' ', $enWords),
            implode(' ', $arWords),
            implode(' ', $heWords),
        ];
    }

    public function uploadImage(Request $request)
    {
        // Validate the request
        $validated = $request->validate([
            'image' => 'required|image|max:10240', // 10MB max
            'custom_name' => 'required|string|max:255',
            'Filename' => 'required|string|max:255',
        ]);

        // Sanitize custom_name - only allow alphanumeric, dash, and underscore
        $customName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $validated['custom_name']);
        $customName = trim($customName, '-_');

        if (empty($customName)) {
            return response()->json([
                'success' => false,
                'error' => 'Custom name cannot be empty or contain only invalid characters.',
            ], 400);
        }

        // Sanitize filename - preserve Unicode characters, only remove dangerous filesystem characters
        $filename = basename($validated['Filename']);
        // Remove only dangerous characters: path separators, null bytes, and control characters
        // Preserve all Unicode characters (including Arabic, Hebrew, Chinese, etc.)
        $filename = preg_replace('/[\x00-\x1F\x7F\/\\\\<>:"|?*]/u', '_', $filename);
        // Remove leading/trailing dots and spaces (Windows filesystem restriction)
        $filename = trim($filename, '. ');

        if (empty($filename)) {
            return response()->json([
                'success' => false,
                'error' => 'Filename cannot be empty or contain only invalid characters.',
            ], 400);
        }

        // Ensure filename has an extension
        if (!pathinfo($filename, PATHINFO_EXTENSION)) {
            $extension = $request->file('image')->getClientOriginalExtension();
            $filename = $filename . '.' . $extension;
        }

        // Create directory in public folder
        $targetDirectory = public_path($customName);

        if (!File::exists($targetDirectory)) {
            File::makeDirectory($targetDirectory, 0755, true);
        }

        // Get the uploaded file
        $file = $request->file('image');

        // Store the image
        try {
            $file->move($targetDirectory, $filename);

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                // Return only the folder name in path as requested
                'path' => $customName,
                'url' => asset($customName . '/' . rawurlencode($filename)),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Image upload failed', [
                'error' => $e->getMessage(),
                'custom_name' => $customName,
                'filename' => $filename,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to upload image: ' . $e->getMessage(),
            ], 500);
        }
    }

}
