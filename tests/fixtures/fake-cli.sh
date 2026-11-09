#!/bin/sh
cat >/dev/null
case "$1" in
    ok)
        printf '%s\n' '{"description":"Test","svg":"<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1440 900\"><rect width=\"10\" height=\"10\"/></svg>"}'
        ;;
    garbage) printf '%s\n' 'Sorry, no.' ;;
    badsvg)
        printf '%s\n' '{"description":"Test","svg":"<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1440 760\"><rect width=\"10\" height=\"10\"/></svg>"}'
        ;;
    slow) sleep 5 ;;
esac
