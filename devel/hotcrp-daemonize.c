#include <unistd.h>
#include <stdlib.h>
#include <dirent.h>
#include <sys/types.h>
#include <ctype.h>
#include <stdio.h>

int main(int argc, char** argv) {
    DIR* dir = opendir("/dev/fd");
    struct dirent* de;
    while (dir && (de = readdir(dir))) {
        if (!isdigit((unsigned char) de->d_name[0])) {
            continue;
        }
        char* ends;
        unsigned long u = strtoul(de->d_name, &ends, 10);
        if (*ends == 0 && (int) u != dirfd(dir) && (int) u > 2) {
            close((int) u);
        }
    }
    closedir(dir);
    if (fork() > 0) {
        exit(0);
    }
    setsid();
    execvp(argv[1], argv + 1);
    exit(127);
}
