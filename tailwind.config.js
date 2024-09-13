/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    "./assets/**/*.js",
    "./templates/**/*.html.twig",
  ],
  safelist: [
    'text-red-700',
  ],
  theme: {
    extend: {
      animation: {
        'fade-in': 'fadeIn .5s ease-out;',
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: 0 },
          '100%': { opacity: 1 },
        },
      },
      colors: {
        'color-pri': '#f7f7ff',
        'color-sec': '#bdd5ea',
        'color-ter': '#577399',
        'color-qua': '#495867',
        'color-qui': '#fe5f55',
      },
      fontFamily: {
        'handwriting': ['Patrick Hand', 'cursive'],
        'handwriting2': ['Indie Flower', 'cursive'],
        'sans': ['Roboto', 'sans-serif'],
        'serif': ['Merriweather', 'serif'],
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
    // plugin(function({ addVariant }) {
    //   addVariant('modal', 'dialog &');
    // })
  ],
}
