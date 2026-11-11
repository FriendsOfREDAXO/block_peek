#!/bin/sh
cat >/dev/null
case "$1" in
    ok)
        printf '%s\n' '{"description":"Test","svg":"<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1440 900\"><rect width=\"10\" height=\"10\"/></svg>"}'
        ;;
    exec)
        while [ "$#" -gt 0 ]; do
            if [ "$1" = '-o' ]; then
                "$0" ok > "$2"
                exit
            fi
            shift
        done
        exit 1
        ;;
    -p) printf '{"result":%s}\n' "$("$0" ok | sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$/"/')" ;;
    path) printf '%s' "$0" ;;
    argv0) printf '%s' "${0##*/}" ;;
    different)
        "$0" ok | sed 's/height=\\"10/height=\\"20/'
        ;;
    sources)
        printf '{"description":"Test","svg":"<svg xmlns=\\"http://www.w3.org/2000/svg\\" viewBox=\\"0 0 1440 900\\"><rect width=\\"10\\" height=\\"10\\"/></svg>","sources":["%s","../outside.php",7]}\n' "$0"
        ;;
    garbage) printf '%s\n' 'Sorry, no.' ;;
    badsvg)
        printf '%s\n' '{"description":"Test","svg":"<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1440 760\"><rect width=\"10\" height=\"10\"/></svg>"}'
        ;;
    slow) sleep 5 ;;
    slowchild)
        sleep 30 &
        echo "$!" > "$2"
        sleep 30
        ;;
esac
