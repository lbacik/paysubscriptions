/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./assets/**/*.js",
    "./templates/**/*.html.twig",
  ],
  safelist: [
    'text-red-700',
  ],
  theme: {
    extend: {
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
  ],
}
