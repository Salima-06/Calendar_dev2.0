// Grade Book: the line shown above the projected grade. Edit the words (or the cut-offs) here.
// "min" is the lowest percentage that gets this message; the list is checked from the top down.
// Letter scale used for the cut-offs: A- starts at 80, B- at 70, D- at 50 (see SCALE in grades.js).
window.DUCKS_GRADE_GREETINGS = {
  empty: '...add a grade and the duck will weigh in.',   // nothing graded yet
  tiers: [
    { min: 80, text: 'top duck incoming!' },                        // A- and up
    { min: 70, text: 'not too shabby~' },                           // B- to B+
    { min: 53, text: 'small duck in a big pond' },                  // D to C+
    { min: -Infinity, text: '...you are planning on passing, right?' }   // D- and below
  ]
};
