<?php
// unicodehelper.php -- helper data tables and functions for Unicode transformations
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

const UTF8_ALPHA_TRANS_2 = "\xC2\xAD\xC3\x80\xC3\x81\xC3\x82\xC3\x83\xC3\x84\xC3\x85\xC3\x86\xC3\x87\xC3\x88\xC3\x89\xC3\x8A\xC3\x8B\xC3\x8C\xC3\x8D\xC3\x8E\xC3\x8F\xC3\x91\xC3\x92\xC3\x93\xC3\x94\xC3\x95\xC3\x96\xC3\x98\xC3\x99\xC3\x9A\xC3\x9B\xC3\x9C\xC3\x9D\xC3\x9F\xC3\xA0\xC3\xA1\xC3\xA2\xC3\xA3\xC3\xA4\xC3\xA5\xC3\xA6\xC3\xA7\xC3\xA8\xC3\xA9\xC3\xAA\xC3\xAB\xC3\xAC\xC3\xAD\xC3\xAE\xC3\xAF\xC3\xB1\xC3\xB2\xC3\xB3\xC3\xB4\xC3\xB5\xC3\xB6\xC3\xB8\xC3\xB9\xC3\xBA\xC3\xBB\xC3\xBC\xC3\xBD\xC3\xBF\xC4\x80\xC4\x81\xC4\x82\xC4\x83\xC4\x84\xC4\x85\xC4\x86\xC4\x87\xC4\x88\xC4\x89\xC4\x8A\xC4\x8B\xC4\x8C\xC4\x8D\xC4\x8E\xC4\x8F\xC4\x90\xC4\x91\xC4\x92\xC4\x93\xC4\x94\xC4\x95\xC4\x96\xC4\x97\xC4\x98\xC4\x99\xC4\x9A\xC4\x9B\xC4\x9C\xC4\x9D\xC4\x9E\xC4\x9F\xC4\xA0\xC4\xA1\xC4\xA2\xC4\xA3\xC4\xA4\xC4\xA5\xC4\xA6\xC4\xA7\xC4\xA8\xC4\xA9\xC4\xAA\xC4\xAB\xC4\xAC\xC4\xAD\xC4\xAE\xC4\xAF\xC4\xB0\xC4\xB1\xC4\xB2\xC4\xB3\xC4\xB4\xC4\xB5\xC4\xB6\xC4\xB7\xC4\xB9\xC4\xBA\xC4\xBB\xC4\xBC\xC4\xBD\xC4\xBE\xC4\xBF\xC5\x80\xC5\x81\xC5\x82\xC5\x83\xC5\x84\xC5\x85\xC5\x86\xC5\x87\xC5\x88\xC5\x8C\xC5\x8D\xC5\x8E\xC5\x8F\xC5\x90\xC5\x91\xC5\x92\xC5\x93\xC5\x94\xC5\x95\xC5\x96\xC5\x97\xC5\x98\xC5\x99\xC5\x9A\xC5\x9B\xC5\x9C\xC5\x9D\xC5\x9E\xC5\x9F\xC5\xA0\xC5\xA1\xC5\xA2\xC5\xA3\xC5\xA4\xC5\xA5\xC5\xA6\xC5\xA7\xC5\xA8\xC5\xA9\xC5\xAA\xC5\xAB\xC5\xAC\xC5\xAD\xC5\xAE\xC5\xAF\xC5\xB0\xC5\xB1\xC5\xB2\xC5\xB3\xC5\xB4\xC5\xB5\xC5\xB6\xC5\xB7\xC5\xB8\xC5\xB9\xC5\xBA\xC5\xBB\xC5\xBC\xC5\xBD\xC5\xBE\xC5\xBF\xC6\x80\xC6\x81\xC6\xA0\xC6\xA1\xC6\xAF\xC6\xB0\xC7\x84\xC7\x85\xC7\x86\xC7\x87\xC7\x88\xC7\x89\xC7\x8A\xC7\x8B\xC7\x8C\xC7\x8D\xC7\x8E\xC7\x8F\xC7\x90\xC7\x91\xC7\x92\xC7\x93\xC7\x94\xC7\x95\xC7\x96\xC7\x97\xC7\x98\xC7\x99\xC7\x9A\xC7\x9B\xC7\x9C\xC7\x9E\xC7\x9F\xC7\xA0\xC7\xA1\xC7\xA2\xC7\xA3\xC7\xA6\xC7\xA7\xC7\xA8\xC7\xA9\xC7\xAA\xC7\xAB\xC7\xAC\xC7\xAD\xC7\xB0\xC7\xB1\xC7\xB2\xC7\xB3\xC7\xB4\xC7\xB5\xC7\xB8\xC7\xB9\xC7\xBA\xC7\xBB\xC7\xBC\xC7\xBD\xC7\xBE\xC7\xBF\xC8\x80\xC8\x81\xC8\x82\xC8\x83\xC8\x84\xC8\x85\xC8\x86\xC8\x87\xC8\x88\xC8\x89\xC8\x8A\xC8\x8B\xC8\x8C\xC8\x8D\xC8\x8E\xC8\x8F\xC8\x90\xC8\x91\xC8\x92\xC8\x93\xC8\x94\xC8\x95\xC8\x96\xC8\x97\xC8\x98\xC8\x99\xC8\x9A\xC8\x9B\xC8\x9E\xC8\x9F\xC8\xA6\xC8\xA7\xC8\xA8\xC8\xA9\xC8\xAA\xC8\xAB\xC8\xAC\xC8\xAD\xC8\xAE\xC8\xAF\xC8\xB0\xC8\xB1\xC8\xB2\xC8\xB3\xCC\x80\xCC\x81\xCC\x82\xCC\x83\xCC\x84\xCC\x85\xCC\x86\xCC\x87\xCC\x88\xCC\x89\xCC\x8A\xCC\x8B\xCC\x8C\xCC\x8D\xCC\x8E\xCC\x8F\xCC\x90\xCC\x91\xCC\x92\xCC\x93\xCC\x94\xCC\x95\xCC\x96\xCC\x97\xCC\x98\xCC\x99\xCC\x9A\xCC\x9B\xCC\x9C\xCC\x9D\xCC\x9E\xCC\x9F\xCC\xA0\xCC\xA1\xCC\xA2\xCC\xA3\xCC\xA4\xCC\xA5\xCC\xA6\xCC\xA7\xCC\xA8\xCC\xA9\xCC\xAA\xCC\xAB\xCC\xAC\xCC\xAD\xCC\xAE\xCC\xAF\xCC\xB0\xCC\xB1\xCC\xB2\xCC\xB3\xCC\xB4\xCC\xB5\xCC\xB6\xCC\xB7\xCC\xB8\xCC\xB9\xCC\xBA\xCC\xBB\xCC\xBC\xCC\xBD\xCC\xBE\xCC\xBF\xCD\x80\xCD\x81\xCD\x82\xCD\x83\xCD\x84\xCD\x85\xCD\x86\xCD\x87\xCD\x88\xCD\x89\xCD\x8A\xCD\x8B\xCD\x8C\xCD\x8D\xCD\x8E\xCD\x8F\xCD\x90\xCD\x91\xCD\x92\xCD\x93\xCD\x94\xCD\x95\xCD\x96\xCD\x97\xCD\x98\xCD\x99\xCD\x9A\xCD\x9B\xCD\x9C\xCD\x9D\xCD\x9E\xCD\x9F\xCD\xA0\xCD\xA1\xCD\xA2";

const UTF8_ALPHA_TRANS_2_OUT = "- A A A A A A AEC E E E E I I I I N O O O O O O U U U U Y ssa a a a a a aec e e e e i i i i n o o o o o o u u u u y y A a A a A a C c C c C c C c D d D d E e E e E e E e E e G g G g G g G g H h H h I i I i I i I i I i IJijJ j K k L l L l L l L l L l N n N n N n O o O o O o OEoeR r R r R r S s S s S s S s T t T t T t U u U u U u U u U u U u W w Y y Y Z z Z z Z z s b B O o U u DZDzdzLJLjljNJNjnjA a I i O o U u U u U u U u U u A a A a AEaeG g K k O o O o j DZDzdzG g N n A a AEaeO o A a A a E e E e I i I i O o O o R r R r U u U u S s T t H h A a E e O o O o O o O o Y y                                                                                                                                                                                                       ";

const UTF8_ALPHA_TRANS_3 = "\xE1\xB8\x80\xE1\xB8\x81\xE1\xB8\x82\xE1\xB8\x83\xE1\xB8\x84\xE1\xB8\x85\xE1\xB8\x86\xE1\xB8\x87\xE1\xB8\x88\xE1\xB8\x89\xE1\xB8\x8A\xE1\xB8\x8B\xE1\xB8\x8C\xE1\xB8\x8D\xE1\xB8\x8E\xE1\xB8\x8F\xE1\xB8\x90\xE1\xB8\x91\xE1\xB8\x92\xE1\xB8\x93\xE1\xB8\x94\xE1\xB8\x95\xE1\xB8\x96\xE1\xB8\x97\xE1\xB8\x98\xE1\xB8\x99\xE1\xB8\x9A\xE1\xB8\x9B\xE1\xB8\x9C\xE1\xB8\x9D\xE1\xB8\x9E\xE1\xB8\x9F\xE1\xB8\xA0\xE1\xB8\xA1\xE1\xB8\xA2\xE1\xB8\xA3\xE1\xB8\xA4\xE1\xB8\xA5\xE1\xB8\xA6\xE1\xB8\xA7\xE1\xB8\xA8\xE1\xB8\xA9\xE1\xB8\xAA\xE1\xB8\xAB\xE1\xB8\xAC\xE1\xB8\xAD\xE1\xB8\xAE\xE1\xB8\xAF\xE1\xB8\xB0\xE1\xB8\xB1\xE1\xB8\xB2\xE1\xB8\xB3\xE1\xB8\xB4\xE1\xB8\xB5\xE1\xB8\xB6\xE1\xB8\xB7\xE1\xB8\xB8\xE1\xB8\xB9\xE1\xB8\xBA\xE1\xB8\xBB\xE1\xB8\xBC\xE1\xB8\xBD\xE1\xB8\xBE\xE1\xB8\xBF\xE1\xB9\x80\xE1\xB9\x81\xE1\xB9\x82\xE1\xB9\x83\xE1\xB9\x84\xE1\xB9\x85\xE1\xB9\x86\xE1\xB9\x87\xE1\xB9\x88\xE1\xB9\x89\xE1\xB9\x8A\xE1\xB9\x8B\xE1\xB9\x8C\xE1\xB9\x8D\xE1\xB9\x8E\xE1\xB9\x8F\xE1\xB9\x90\xE1\xB9\x91\xE1\xB9\x92\xE1\xB9\x93\xE1\xB9\x94\xE1\xB9\x95\xE1\xB9\x96\xE1\xB9\x97\xE1\xB9\x98\xE1\xB9\x99\xE1\xB9\x9A\xE1\xB9\x9B\xE1\xB9\x9C\xE1\xB9\x9D\xE1\xB9\x9E\xE1\xB9\x9F\xE1\xB9\xA0\xE1\xB9\xA1\xE1\xB9\xA2\xE1\xB9\xA3\xE1\xB9\xA4\xE1\xB9\xA5\xE1\xB9\xA6\xE1\xB9\xA7\xE1\xB9\xA8\xE1\xB9\xA9\xE1\xB9\xAA\xE1\xB9\xAB\xE1\xB9\xAC\xE1\xB9\xAD\xE1\xB9\xAE\xE1\xB9\xAF\xE1\xB9\xB0\xE1\xB9\xB1\xE1\xB9\xB2\xE1\xB9\xB3\xE1\xB9\xB4\xE1\xB9\xB5\xE1\xB9\xB6\xE1\xB9\xB7\xE1\xB9\xB8\xE1\xB9\xB9\xE1\xB9\xBA\xE1\xB9\xBB\xE1\xB9\xBC\xE1\xB9\xBD\xE1\xB9\xBE\xE1\xB9\xBF\xE1\xBA\x80\xE1\xBA\x81\xE1\xBA\x82\xE1\xBA\x83\xE1\xBA\x84\xE1\xBA\x85\xE1\xBA\x86\xE1\xBA\x87\xE1\xBA\x88\xE1\xBA\x89\xE1\xBA\x8A\xE1\xBA\x8B\xE1\xBA\x8C\xE1\xBA\x8D\xE1\xBA\x8E\xE1\xBA\x8F\xE1\xBA\x90\xE1\xBA\x91\xE1\xBA\x92\xE1\xBA\x93\xE1\xBA\x94\xE1\xBA\x95\xE1\xBA\x96\xE1\xBA\x97\xE1\xBA\x98\xE1\xBA\x99\xE1\xBA\x9B\xE1\xBA\xA0\xE1\xBA\xA1\xE1\xBA\xA2\xE1\xBA\xA3\xE1\xBA\xA4\xE1\xBA\xA5\xE1\xBA\xA6\xE1\xBA\xA7\xE1\xBA\xA8\xE1\xBA\xA9\xE1\xBA\xAA\xE1\xBA\xAB\xE1\xBA\xAC\xE1\xBA\xAD\xE1\xBA\xAE\xE1\xBA\xAF\xE1\xBA\xB0\xE1\xBA\xB1\xE1\xBA\xB2\xE1\xBA\xB3\xE1\xBA\xB4\xE1\xBA\xB5\xE1\xBA\xB6\xE1\xBA\xB7\xE1\xBA\xB8\xE1\xBA\xB9\xE1\xBA\xBA\xE1\xBA\xBB\xE1\xBA\xBC\xE1\xBA\xBD\xE1\xBA\xBE\xE1\xBA\xBF\xE1\xBB\x80\xE1\xBB\x81\xE1\xBB\x82\xE1\xBB\x83\xE1\xBB\x84\xE1\xBB\x85\xE1\xBB\x86\xE1\xBB\x87\xE1\xBB\x88\xE1\xBB\x89\xE1\xBB\x8A\xE1\xBB\x8B\xE1\xBB\x8C\xE1\xBB\x8D\xE1\xBB\x8E\xE1\xBB\x8F\xE1\xBB\x90\xE1\xBB\x91\xE1\xBB\x92\xE1\xBB\x93\xE1\xBB\x94\xE1\xBB\x95\xE1\xBB\x96\xE1\xBB\x97\xE1\xBB\x98\xE1\xBB\x99\xE1\xBB\x9A\xE1\xBB\x9B\xE1\xBB\x9C\xE1\xBB\x9D\xE1\xBB\x9E\xE1\xBB\x9F\xE1\xBB\xA0\xE1\xBB\xA1\xE1\xBB\xA2\xE1\xBB\xA3\xE1\xBB\xA4\xE1\xBB\xA5\xE1\xBB\xA6\xE1\xBB\xA7\xE1\xBB\xA8\xE1\xBB\xA9\xE1\xBB\xAA\xE1\xBB\xAB\xE1\xBB\xAC\xE1\xBB\xAD\xE1\xBB\xAE\xE1\xBB\xAF\xE1\xBB\xB0\xE1\xBB\xB1\xE1\xBB\xB2\xE1\xBB\xB3\xE1\xBB\xB4\xE1\xBB\xB5\xE1\xBB\xB6\xE1\xBB\xB7\xE1\xBB\xB8\xE1\xBB\xB9\xE2\x80\x90\xE2\x80\x91\xE2\x80\x92\xE2\x80\x93\xE2\x80\x98\xE2\x80\x99\xE2\x80\x9C\xE2\x80\x9D\xE2\x84\xAA\xEF\xAC\x80\xEF\xAC\x81\xEF\xAC\x82\xEF\xAC\x83\xEF\xAC\x84\xEF\xAC\x85\xEF\xAC\x86\xEF\xBC\x88\xEF\xBC\x89\xEF\xBC\xA1\xEF\xBC\xA2\xEF\xBC\xA3\xEF\xBC\xA4\xEF\xBC\xA5\xEF\xBC\xA6\xEF\xBC\xA7\xEF\xBC\xA8\xEF\xBC\xA9\xEF\xBC\xAA\xEF\xBC\xAB\xEF\xBC\xAC\xEF\xBC\xAD\xEF\xBC\xAE\xEF\xBC\xAF\xEF\xBC\xB0\xEF\xBC\xB1\xEF\xBC\xB2\xEF\xBC\xB3\xEF\xBC\xB4\xEF\xBC\xB5\xEF\xBC\xB6\xEF\xBC\xB7\xEF\xBC\xB8\xEF\xBC\xB9\xEF\xBC\xBA\xEF\xBC\xBB\xEF\xBC\xBD\xEF\xBD\x81\xEF\xBD\x82\xEF\xBD\x83\xEF\xBD\x84\xEF\xBD\x85\xEF\xBD\x86\xEF\xBD\x87\xEF\xBD\x88\xEF\xBD\x89\xEF\xBD\x8A\xEF\xBD\x8B\xEF\xBD\x8C\xEF\xBD\x8D\xEF\xBD\x8E\xEF\xBD\x8F\xEF\xBD\x90\xEF\xBD\x91\xEF\xBD\x92\xEF\xBD\x93\xEF\xBD\x94\xEF\xBD\x95\xEF\xBD\x96\xEF\xBD\x97\xEF\xBD\x98\xEF\xBD\x99\xEF\xBD\x9A\xEF\xBD\x9B\xEF\xBD\x9D";

const UTF8_ALPHA_TRANS_3_OUT = "A  a  B  b  B  b  B  b  C  c  D  d  D  d  D  d  D  d  D  d  E  e  E  e  E  e  E  e  E  e  F  f  G  g  H  h  H  h  H  h  H  h  H  h  I  i  I  i  K  k  K  k  K  k  L  l  L  l  L  l  L  l  M  m  M  m  M  m  N  n  N  n  N  n  N  n  O  o  O  o  O  o  O  o  P  p  P  p  R  r  R  r  R  r  R  r  S  s  S  s  S  s  S  s  S  s  T  t  T  t  T  t  T  t  U  u  U  u  U  u  U  u  U  u  V  v  V  v  W  w  W  w  W  w  W  w  W  w  X  x  X  x  Y  y  Z  z  Z  z  Z  z  h  t  w  y  s  A  a  A  a  A  a  A  a  A  a  A  a  A  a  A  a  A  a  A  a  A  a  A  a  E  e  E  e  E  e  E  e  E  e  E  e  E  e  E  e  I  i  I  i  O  o  O  o  O  o  O  o  O  o  O  o  O  o  O  o  O  o  O  o  O  o  O  o  U  u  U  u  U  u  U  u  U  u  U  u  U  u  Y  y  Y  y  Y  y  Y  y  -  -  -  -  '  '  \"  \"  K  ff fi fl ffifflst st (  )  A  B  C  D  E  F  G  H  I  J  K  L  M  N  O  P  Q  R  S  T  U  V  W  X  Y  Z  [  ]  a  b  c  d  e  f  g  h  i  j  k  l  m  n  o  p  q  r  s  t  u  v  w  x  y  z  {  }  ";

const UTF8_FROM_MAC_OS_ROMAN = "\xC3\x84\x20\xC3\x85\x20\xC3\x87\x20\xC3\x89\x20\xC3\x91\x20\xC3\x96\x20\xC3\x9C\x20\xC3\xA1\x20\xC3\xA0\x20\xC3\xA2\x20\xC3\xA4\x20\xC3\xA3\x20\xC3\xA5\x20\xC3\xA7\x20\xC3\xA9\x20\xC3\xA8\x20\xC3\xAA\x20\xC3\xAB\x20\xC3\xAD\x20\xC3\xAC\x20\xC3\xAE\x20\xC3\xAF\x20\xC3\xB1\x20\xC3\xB3\x20\xC3\xB2\x20\xC3\xB4\x20\xC3\xB6\x20\xC3\xB5\x20\xC3\xBA\x20\xC3\xB9\x20\xC3\xBB\x20\xC3\xBC\x20\xE2\x80\xA0\xC2\xB0\x20\xC2\xA2\x20\xC2\xA3\x20\xC2\xA7\x20\xE2\x80\xA2\xC2\xB6\x20\xC3\x9F\x20\xC2\xAE\x20\xC2\xA9\x20\xE2\x84\xA2\xC2\xB4\x20\xC2\xA8\x20\xE2\x89\xA0\xC3\x86\x20\xC3\x98\x20\xE2\x88\x9E\xC2\xB1\x20\xE2\x89\xA4\xE2\x89\xA5\xC2\xA5\x20\xC2\xB5\x20\xE2\x88\x82\xE2\x88\x91\xE2\x88\x8F\xCF\x80\x20\xE2\x88\xAB\xC2\xAA\x20\xC2\xBA\x20\xCE\xA9\x20\xC3\xA6\x20\xC3\xB8\x20\xC2\xBF\x20\xC2\xA1\x20\xC2\xAC\x20\xE2\x88\x9A\xC6\x92\x20\xE2\x89\x88\xE2\x88\x86\xC2\xAB\x20\xC2\xBB\x20\xE2\x80\xA6\xC2\xA0\x20\xC3\x80\x20\xC3\x83\x20\xC3\x95\x20\xC5\x92\x20\xC5\x93\x20\xE2\x80\x93\xE2\x80\x94\xE2\x80\x9C\xE2\x80\x9D\xE2\x80\x98\xE2\x80\x99\xC3\xB7\x20\xE2\x97\x8A\xC3\xBF\x20\xC5\xB8\x20\xE2\x81\x84\xE2\x82\xAC\xE2\x80\xB9\xE2\x80\xBA\xEF\xAC\x81\xEF\xAC\x82\xE2\x80\xA1\xC2\xB7\x20\xE2\x80\x9A\xE2\x80\x9E\xE2\x80\xB0\xC3\x82\x20\xC3\x8A\x20\xC3\x81\x20\xC3\x8B\x20\xC3\x88\x20\xC3\x8D\x20\xC3\x8E\x20\xC3\x8F\x20\xC3\x8C\x20\xC3\x93\x20\xC3\x94\x20\xEF\xA3\xBF\xC3\x92\x20\xC3\x9A\x20\xC3\x9B\x20\xC3\x99\x20\xC4\xB1\x20\xCB\x86\x20\xCB\x9C\x20\xC2\xAF\x20\xCB\x98\x20\xCB\x99\x20\xCB\x9A\x20\xC2\xB8\x20\xCB\x9D\x20\xCB\x9B\x20\xCB\x87\x20";
const UTF8_FROM_WINDOWS_1252 = "\xE2\x82\xAC\x20\x20\x20\xE2\x80\x9A\xC6\x92\x20\xE2\x80\x9E\xE2\x80\xA6\xE2\x80\xA0\xE2\x80\xA1\xCB\x86\x20\xE2\x80\xB0\xC5\xA0\x20\xE2\x80\xB9\xC5\x92\x20\x20\x20\x20\xC5\xBD\x20\x20\x20\x20\x20\x20\x20\xE2\x80\x98\xE2\x80\x99\xE2\x80\x9C\xE2\x80\x9D\xE2\x80\xA2\xE2\x80\x93\xE2\x80\x94\xCB\x9C\x20\xE2\x84\xA2\xC5\xA1\x20\xE2\x80\xBA\xC5\x93\x20\x20\x20\x20\xC5\xBE\x20\xC5\xB8\x20\xC2\xA0\x20\xC2\xA1\x20\xC2\xA2\x20\xC2\xA3\x20\xC2\xA4\x20\xC2\xA5\x20\xC2\xA6\x20\xC2\xA7\x20\xC2\xA8\x20\xC2\xA9\x20\xC2\xAA\x20\xC2\xAB\x20\xC2\xAC\x20\xC2\xAD\x20\xC2\xAE\x20\xC2\xAF\x20\xC2\xB0\x20\xC2\xB1\x20\xC2\xB2\x20\xC2\xB3\x20\xC2\xB4\x20\xC2\xB5\x20\xC2\xB6\x20\xC2\xB7\x20\xC2\xB8\x20\xC2\xB9\x20\xC2\xBA\x20\xC2\xBB\x20\xC2\xBC\x20\xC2\xBD\x20\xC2\xBE\x20\xC2\xBF\x20\xC3\x80\x20\xC3\x81\x20\xC3\x82\x20\xC3\x83\x20\xC3\x84\x20\xC3\x85\x20\xC3\x86\x20\xC3\x87\x20\xC3\x88\x20\xC3\x89\x20\xC3\x8A\x20\xC3\x8B\x20\xC3\x8C\x20\xC3\x8D\x20\xC3\x8E\x20\xC3\x8F\x20\xC3\x90\x20\xC3\x91\x20\xC3\x92\x20\xC3\x93\x20\xC3\x94\x20\xC3\x95\x20\xC3\x96\x20\xC3\x97\x20\xC3\x98\x20\xC3\x99\x20\xC3\x9A\x20\xC3\x9B\x20\xC3\x9C\x20\xC3\x9D\x20\xC3\x9E\x20\xC3\x9F\x20\xC3\xA0\x20\xC3\xA1\x20\xC3\xA2\x20\xC3\xA3\x20\xC3\xA4\x20\xC3\xA5\x20\xC3\xA6\x20\xC3\xA7\x20\xC3\xA8\x20\xC3\xA9\x20\xC3\xAA\x20\xC3\xAB\x20\xC3\xAC\x20\xC3\xAD\x20\xC3\xAE\x20\xC3\xAF\x20\xC3\xB0\x20\xC3\xB1\x20\xC3\xB2\x20\xC3\xB3\x20\xC3\xB4\x20\xC3\xB5\x20\xC3\xB6\x20\xC3\xB7\x20\xC3\xB8\x20\xC3\xB9\x20\xC3\xBA\x20\xC3\xBB\x20\xC3\xBC\x20\xC3\xBD\x20\xC3\xBE\x20\xC3\xBF\x20";

class UnicodeHelper {
    /** @readonly */
    public static $f_ligature_map = [
        "ﬀ" => "ff", "ﬁ" => "fi", "ﬂ" => "fl", "ﬃ" => "ffi", "ﬄ" => "ffl"
    ];

    /** @var array<string,int> */
    private static $deaccent_map;

    /** @param string $ins
     * @param int $step */
    private static function add_deaccent_map($ins, $step) {
        $l = strlen($ins);
        $tag = $step === 3 ? 1 : 0;
        for ($i = 0; $i < $l; $i += $step) {
            $in = substr($ins, $i, $step);
            self::$deaccent_map[$in] = ($i << 1) | $tag;
        }
    }

    private static function make_deaccent_map() {
        if (self::$deaccent_map === null) {
            self::$deaccent_map = [];
            self::add_deaccent_map(UTF8_ALPHA_TRANS_2, 2);
            self::add_deaccent_map(UTF8_ALPHA_TRANS_3, 3);
        }
    }

    /** @param int $di
     * @return string */
    private static function deaccent_result($di) {
        if (($di & 1) !== 0) {
            return trim(substr(UTF8_ALPHA_TRANS_3_OUT, $di >> 1, 3));
        } else {
            return trim(substr(UTF8_ALPHA_TRANS_2_OUT, $di >> 1, 2));
        }
    }

    /** @param string $x
     * @return string */
    static function deaccent($x) {
        if (preg_match_all("/[\xC0-\xFF]/", $x, $m, PREG_OFFSET_CAPTURE)) {
            if (self::$deaccent_map === null) {
                self::make_deaccent_map();
            }
            $first = 0;
            $out = "";
            foreach ($m[0] as $mx) {
                $i = $mx[1];
                $l = ord($mx[0]) < 0xE0 ? 2 : 3;
                $ch = substr($x, $i, $l);
                if (($di = self::$deaccent_map[$ch] ?? null) !== null) {
                    $out .= substr($x, $first, $i - $first) . self::deaccent_result($di);
                    $first = $i + $l;
                }
            }
            $x = $out . substr($x, $first);
        }
        return $x;
    }

    /** @param string $x
     * @return array{string,list<int>} */
    static function deaccent_offsets($x) {
        $offsetmap = [0, 0];
        if (preg_match_all("/[\xC0-\xFF]/", $x, $m, PREG_OFFSET_CAPTURE)) {
            if (self::$deaccent_map === null) {
                self::make_deaccent_map();
            }
            $first = 0;
            $out = "";
            foreach ($m[0] as $mx) {
                $i = $mx[1];
                $l = ord($mx[0]) < 0xE0 ? 2 : 3;
                $ch = substr($x, $i, $l);
                if (($di = self::$deaccent_map[$ch] ?? null) !== null) {
                    $out .= substr($x, $first, $i - $first) . self::deaccent_result($di);
                    $first = $i + $l;
                    $offsetmap[] = strlen($out);
                    $offsetmap[] = $first;
                }
            }
            $x = $out . substr($x, $first);
        }
        return [$x, $offsetmap];
    }

    /** @param list<int> $offsetmap
     * @param int $offset
     * @return int */
    static function deaccent_translate_offset($offsetmap, $offset) {
        $n = count($offsetmap);
        for ($i = 2; $i < $n && $offsetmap[$i] <= $offset; $i += 2) {
            /* do nothing */;
        }
        return $offsetmap[$i - 1] + ($offset - $offsetmap[$i - 2]);
    }

    /** @param string $str
     * @return string */
    static function normalize($str) {
        return normalizer_normalize($str);
    }

    /** @param string $str
     * @return string */
    static function windows_1252_to_utf8($str) {
        return preg_replace_callback('/[\200-\377]/', function ($m) {
            return rtrim(substr(UTF8_FROM_WINDOWS_1252, 3 * (ord($m[0]) - 128), 3));
        }, $str);
    }

    /** @param string $str
     * @return string */
    static function mac_os_roman_to_utf8($str) {
        return preg_replace_callback('/[\200-\377]/', function ($m) {
            return rtrim(substr(UTF8_FROM_MAC_OS_ROMAN, 3 * (ord($m[0]) - 128), 3));
        }, $str);
    }

    /** @param string $str
     * @param int $pos
     * @return string */
    static function utf8_char_at($str, $pos) {
        $len = strlen($str);
        $ch = ord($str[$pos]);
        if ($ch < 0x80) {
            return $str[$pos];
        } else if ($ch < 0xC2
                   || $ch > 0xF4
                   || $pos + 1 >= $len) {
            return "";
        }
        $ch1 = ord($str[$pos + 1]);
        if (($ch1 & 0xC0) !== 0x80
            || ($ch === 0xE0 && $ch1 < 0xA0)  // overlong
            || ($ch === 0xED && $ch1 >= 0xA0) // surrogate
            || ($ch === 0xF0 && $ch1 < 0x90)  // overlong
            || ($ch === 0xF4 && $ch1 >= 0x90)) { // invalid
            return "";
        } else if ($ch < 0xE0) {
            return substr($str, $pos, 2);
        } else if ($pos + 2 >= $len
                   || (ord($str[$pos + 2]) & 0xC0) !== 0x80) {
            return "";
        } else if ($ch < 0xF0) {
            return substr($str, $pos, 3);
        } else if ($pos + 3 >= $len
                   || (ord($str[$pos + 3]) & 0xC0) !== 0x80) {
            return "";
        } else {
            return substr($str, $pos, 4);
        }
    }

    /** @param string $str
     * @return int */
    static function utf8_ord($str, $pos = 0) {
        $len = strlen($str);
        $ch = ord($str[$pos]);
        if ($ch < 0x80) {
            return $ch;
        } else if ($ch < 0xC2
                   || $ch > 0xF4
                   || $pos + 1 >= $len) {
            return -1;
        }
        $ch1 = ord($str[$pos + 1]);
        if (($ch1 & 0xC0) !== 0x80
            || ($ch === 0xE0 && $ch1 < 0xA0)  // overlong
            || ($ch === 0xED && $ch1 >= 0xA0) // surrogate
            || ($ch === 0xF0 && $ch1 < 0x90)  // overlong
            || ($ch === 0xF4 && $ch1 >= 0x90)) { // invalid
            return -1;
        } else if ($ch < 0xE0) {
            return (($ch & 0x1F) << 6) | ($ch1 & 0x3F);
        } else if ($pos + 2 >= $len
                   || (($ch2 = ord($str[$pos + 2])) & 0xC0) !== 0x80) {
            return -1;
        } else if ($ch < 0xF0) {
            return (($ch & 0x0F) << 12) | (($ch1 & 0x3F) << 6) | ($ch2 & 0x3F);
        } else if ($pos + 3 >= $len
                   || (($ch3 = ord($str[$pos + 3])) & 0xC0) !== 0x80) {
            return -1;
        } else {
            return (($ch & 0x07) << 18) | (($ch1 & 0x3F) << 12)
                | (($ch2 & 0x3F) << 6) | ($ch3 & 0x3F);
        }
    }

    /** @param int $ch
     * @return list<int> */
    static function utf16_seq($ch) {
        if ($ch >= 0 && $ch <= 0xFFFF) {
            return [$ch];
        } else if ($ch >= 0x10000 && $ch <= 0x10FFFF) {
            $ch -= 0x10000;
            return [0xD800 | ($ch >> 10), 0xDC00 | ($ch & 0x3FF)];
        } else {
            return [];
        }
    }

    /** @param int $ch
     * @return string */
    static function utf8_chr($ch) {
        assert($ch >= 0 && $ch <= 0x10FFFF);
        if ($ch < 0x80) {
            return chr($ch);
        } else if ($ch < 0x800) {
            return chr(0xC0 | ($ch >> 6)) . chr(0x80 | ($ch & 0x3F));
        } else if ($ch < 0x10000) {
            return chr(0xE0 | ($ch >> 12)) . chr(0x80 | (($ch >> 6) & 0x3F)) . chr(0x80 | ($ch & 0x3F));
        } else {
            return chr(0xF0 | ($ch >> 18)) . chr(0x80 | (($ch >> 12) & 0x3F)) . chr(0x80 | (($ch >> 6) & 0x3F)) . chr(0x80 | ($ch & 0x3F));
        }
    }

    /** @param int $ch
     * @return 1|2|3|4 */
    static function utf8_chrlen($ch) {
        assert($ch >= 0 && $ch <= 0x10FFFF);
        return 1 + (int) ($ch >= 0x80) + (int) ($ch >= 0x800) + (int) ($ch >= 0x10000);
    }

    /** @param string $str
     * @return string */
    static function utf8_to_html_entities($str, $flag = ENT_NOQUOTES) {
        if ($flag & ENT_IGNORE) {
            $start = "";
        } else if (($flag & ENT_QUOTES) == ENT_QUOTES) {
            $start = "&<>\"'";
        } else if (($flag & ENT_COMPAT) == ENT_COMPAT) {
            $start = "&<>\"";
        } else {
            $start = "&<>";
        }
        $flag = ($flag & (ENT_HTML401 | ENT_XML1 | ENT_XHTML | ENT_HTML5)) | ENT_QUOTES;
        return preg_replace_callback('/[' . $start . '\200-\377][\200-\277]*/',
                                     function ($m) use ($flag) {
            if (($f = UnicodeHelper::$f_ligature_map[$m[0]] ?? null)) {
                return $f;
            }
            $e = htmlentities($m[0], $flag, "UTF-8");
            if (substr($e, 0, 1) !== "&") {
                $n = ord($m[0][0]);
                if ($n < 0xE0) {
                    $n &= 0x1F;
                } else if ($n < 0xF0) {
                    $n &= 0x0F;
                } else {
                    $n &= 0x07;
                }
                for ($i = 1; $i < strlen($m[0]); ++$i) {
                    $n = ($n << 6) | (ord($m[0][$i]) & 0x3F);
                }
                $e = "&#" . $n . ";";
            }
            return $e;
        }, $str);
    }

    /** @param string $str
     * @return string */
    static function utf8_to_xml_numeric_entities($str, $flag = ENT_NOQUOTES) {
        if ($flag & ENT_IGNORE) {
            $start = "";
        } else if (($flag & ENT_QUOTES) == ENT_QUOTES) {
            $start = "&<>\"'";
        } else if (($flag & ENT_COMPAT) == ENT_COMPAT) {
            $start = "&<>\"";
        } else {
            $start = "&<>";
        }
        return preg_replace_callback('/[' . $start . '\200-\377][\200-\277]*/',
                                     function ($m) {
            if (($f = UnicodeHelper::$f_ligature_map[$m[0]] ?? null)) {
                return $f;
            } else {
                $n = ord($m[0][0]);
                if ($n < 0x80) {
                    // do nothing
                } else if ($n < 0xE0) {
                    $n &= 0x1F;
                } else if ($n < 0xF0) {
                    $n &= 0x0F;
                } else {
                    $n &= 0x07;
                }
                for ($i = 1; $i < strlen($m[0]); ++$i) {
                    $n = ($n << 6) | (ord($m[0][$i]) & 0x3F);
                }
                return "&#" . $n . ";";
            }
        }, $str);
    }

    /** @param string $str
     * @return int */
    static function utf8_glyphlen($str) {
        return preg_match_all('/\X/u', $str);
    }

    /** @param string $str
     * @return int */
    static function utf16_strlen($str) {
        return strlen($str) - preg_match_all('/[\xC0-\xDF]|[\xE0-\xEF][\x80-\xBF]|[\xF0-\xF7][\x80-\xBF]/', $str);
    }

    /** @param string $str
     * @param int $len
     * @return string|false */
    static function utf8_prefix($str, $len) {
        preg_match('/\A\pM*\X{0,' . $len . '}/u', $str, $m);
        return isset($m[0]) ? $m[0] : false;
    }

    /** @param string $str
     * @param int $len
     * @return string */
    static function utf8_word_prefix($str, $len) {
        if (strlen($str) > $len
            && (preg_match('/\A\pM*\X{0,' . max($len - 1, 0) . '}(?!\pZ|\s)\X(?=\pZ|\s)/u', $str, $m)
                || preg_match('/\A\pM*\X{1,' . max($len, 1) . '}(?:(?!\pZ|\s)\X)*/u', $str, $m))) {
            return $m[0];
        } else {
            return $str;
        }
    }

    /** @param string &$str
     * @param int $len
     * @param bool $preserve_space
     * @return string|false */
    static function utf8_line_break(&$str, $len, $preserve_space = false) {
        if ($str === "") {
            return false;
        }
        $line = self::utf8_word_prefix($str, $len);
        if (($nl = strpos($line, "\n")) !== false) {
            $line = substr($line, 0, $nl);
        }
        $pos = strlen($line);
        if (preg_match('/\G(?:\pZ|[\t\f\r])*\n?/u', $str, $m, 0, $pos)) {
            $pos += strlen($m[0]);
        }
        if ($preserve_space && $pos !== strlen($line)) {
            $ends_nl = $str[$pos - 1] === "\n" ? 1 : 0;
            $line .= substr($str, strlen($line), $pos - strlen($line) - $ends_nl);
        }
        $str = substr($str, $pos);
        return $line;
    }

    /** @param string $str
     * @param int $len
     * @param bool $preserve_space
     * @return array{string|false,string} */
    static function utf8_line_break_parts($str, $len, $preserve_space = false) {
        $line = self::utf8_line_break($str, $len, $preserve_space);
        return [$line, $str];
    }

    /** @param string $str
     * @param int $len
     * @return string */
    static function utf8_abbreviate($str, $len) {
        $pfx = self::utf8_word_prefix($str, $len);
        if (strlen($pfx) < strlen($str)) {
            return "$pfx...";
        } else {
            return $pfx;
        }
    }

    /** @param string $str
     * @return string */
    static function demojibake($str) {
        return preg_replace_callback('/\xC3[\x80-\x8F]\xC2[\x80-\xBF]/', function ($m) {
            return chr(ord(substr($m[0], 1, 1)) + 0x40) . substr($m[0], 3, 1);
        }, $str);
    }

    /** @param string $str
     * @return string */
    static function remove_f_ligatures($str) {
        return preg_replace_callback("/\xEF\xAC[\x80-\x84]/", function ($m) {
            return UnicodeHelper::$f_ligature_map[$m[0]];
        }, $str);
    }

    /** @param string $str
     * @return string */
    static function utf8_truncate_invalid($str) {
        $len = strlen($str);
        $c = $len ? ord($str[$len - 1]) : 0;
        if ($c < 0x80) {
            return $str;
        } else if ($c >= 0xC0
                   || $len === 1
                   || ($d = ord($str[$len - 2])) < 0x80) {
            return substr($str, 0, $len - 1);
        } else if ($d >= 0xC0
                   && $d < 0xE0) {
            return $str;
        } else if ($d >= 0xC0
                   || $len === 2
                   || ($e = ord($str[$len - 3])) < 0x80) {
            return substr($str, 0, $len - 2);
        } else if ($e >= 0xE0
                   && $e < 0xF0) {
            return $str;
        } else if ($e >= 0xC0
                   || $len === 3
                   || ord($str[$len - 4]) < 0xF0) {
            return substr($str, 0, $len - 3);
        } else {
            return $str;
        }
    }

    /** @param string $str
     * @return string */
    static function utf8_replace_invalid($str) {
        $t = "";
        while ($str !== "" && $str !== false) {
            if (preg_match('/\A[\x01-\x7f]+/', $str, $m)
                || preg_match('/\A(?:[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xec\xee\xef][\x80-\xbf][\x80-\xbf]|\xed[\x80-\x9f][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf][\x80-\xbf]|[\xf1-\xf3][\x80-\xbf][\x80-\xbf][\x80-\xbf]|\xf4[\x80-\x8f][\x80-\xbf][\x80-\xbf])+/', $str, $m)) {
                $t .= $m[0];
                $str = substr($str, strlen($m[0]));
            } else {
                if (substr($str, 0, 1) !== "\x00") {
                    $t .= chr(0x7f);
                }
                $str = substr($str, 1);
            }
        }
        return $t;
    }
}
