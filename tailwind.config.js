import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    safelist: [
        'bg-purple-600',
        'bg-purple-700',
        'hover:bg-purple-700',
        'text-purple-700',
        'text-purple-400',
        'border-purple-500',
        'focus:ring-purple-500',
        'focus:border-purple-500',
        // Progress bar styles
        'bg-blue-500',
        'bg-blue-700',
        'bg-blue-400',
        'text-blue-700',
        'text-blue-400',
        'h-2.5',
        'bg-gray-200',
        'bg-gray-700',
        'bg-red-50',
        'bg-red-900',
        'border-red-200',
        'border-red-800',
        'text-red-600',
        'text-red-400',
        'text-red-700',
        'text-red-300',
        // Full Exam View button styles (Nova PHP classes)
        'bg-blue-600',
        'hover:bg-blue-700',
        'text-white',
        'inline-flex',
        'items-center',
        'px-4',
        'py-2',
        'font-semibold',
        'rounded-lg',
        'shadow-md',
        'transition',
        'duration-150',
        'ease-in-out',
        'w-5',
        'h-5',
        'mr-2',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },
    plugins: [],
};
