<!--
	// See KB article about changing this dynamic HTML
	dynamicanimAttr = "dynamicanimation"
	animCancel = "skipanim"
	fpanimationPrefix = "fpAnim"
	animateElements = new Array()
	currentElement = 0
	speed = 1
	stepsZoom = 8
	stepsWord = 8
	stepsFly = 17
	stepsSpiral = 16
	stepsSpiralWord = 19
	stepsElastic = 32
	steps = stepsZoom
	step = 0
	cornerPhase=0
	outEffect=0
	function remSuffix(str)
	{
		ind=str.indexOf("FP")
		str = str.substring(0,ind)
		return str
	}
	function dynAnimOut(el)
	{
		outEffect=1
		dynAnimation(el)
		outEffect=0
	}
	function dynAnimation(obj)
	{
		animateElements = new Array()
		var ms = navigator.appVersion.indexOf("MSIE")
		ie4 = (ms>0) && (parseInt(navigator.appVersion.substring(ms+5, ms+6)) >= 4)
		if(!ie4)
		{
			if((navigator.appName == "Netscape") &&
				(parseInt(navigator.appVersion.substring(0, 1)) >= 4))
				doc_els=document.layers
			else
				return
		}
		else
			doc_els=document.all
        if(outEffect && !ie4)
            return
		if(ie4)
		{
		    for (index=document.all.length-1; index >= document.body.sourceIndex; index--)
			{
				el = document.all[index]
				if(outEffect && el != obj)
					continue
				if(outEffect)
					animationId = el.id.substring(9,el.id.length)
				else
					animationId = el.id.substring(6,el.id.length)
				animation=remSuffix(animationId)
				if(null != animation)
				{
					altcnt=0
					if(	animation == "dropWord"				||
						animation == "flyTopRightWord"			||
						animation == "flyBottomRightWord"		||
						animation == "waveWords"			||
						animation == "hopWords")
					{
						ih = el.innerHTML
						outString = ""
						i1 = 0
						iend = ih.length
						while(true)
						{
							i2 = startWord(ih, i1)
							if(i2 == -1)
							i2 = iend
							outWord(ih, i1, i2, false, "", outEffect ? obj.id : el.id)
							if(i2 == iend)
								break
							i1 = i2
							i2 = endWord(ih, i1)
							if(i2 == -1)
								i2 = iend
							if (animation == "waveWords")
								outWordAlt(ih, i1, i2, true, animation, altcnt)
							else
								outWord(ih, i1, i2, true, (outEffect ? "Out" : "") + animation,
									outEffect ?  obj.id : el.id)

							if(i2 == iend)
								break
							i1 = i2
							altcnt++
						}
						document.all[index].innerHTML = outString
						document.all[index].style.posLeft = 0
						document.all[index].setAttribute(animCancel, true)
						document.all[index].style.visibility="visible"
					}
				}
			}
		}
		i = 0
		for (index=0; index < doc_els.length; index++)
		{
			el = doc_els[index]
			if(0 != el.id.indexOf(fpanimationPrefix))
				continue
			if (ie4)
			{
				elprops=el.style
				scrollOffsetTop=document.body.scrollTop
				docHeight=document.body.offsetHeight
				docWidth=document.body.offsetWidth
				elW=100
				elH=el.offsetHeight
			}
			else
			{
				elprops=el
				scrollOffsetTop=window.pageYOffset
				docHeight=window.innerHeight
				docWidth=window.innerWidth
				elW=el.clip.width
				elH=el.clip.height
			}
			if(outEffect)
				animationId = el.id.substring(9,el.id.length)
			else
				animationId = el.id.substring(6,el.id.length)
			animation=remSuffix(animationId)
			if(outEffect && (obj != el))
			{
				if(el.SRCID != obj.id)
					continue
			}
			if (null != animation )
			{
				if(ie4 && null!=el.getAttribute(animCancel, false))
					continue
				if(!ie4)
				{
					elprops.posLeft=elprops.left
					elprops.posTop=elprops.top
				}
				el.startL=offsetLeft(el)
				if(animation == "flyLeft")
				{
					elprops.posLeft = -offsetLeft(el)-elW
					elprops.posTop = 0
				}
				else if(animation == "flyRight" || animation=="elasticRight")
				{
					elprops.posLeft = -offsetLeft(el)+docWidth
					elprops.posTop = 0
				}
				else if(animation == "flyTop" || animation == "dropWord")
				{
					elprops.posLeft = 0
					elprops.posTop = scrollOffsetTop-offsetTop(el)-elH
				}
				else if(animation == "flyBottom" || animation == "elasticBottom")
				{
					elprops.posLeft = 0
					elprops.posTop = scrollOffsetTop-offsetTop(el)+docHeight 
				}
				else if(animation == "flyTopLeft")
				{
					elprops.posLeft = -offsetLeft(el)-elW
					elprops.posTop = scrollOffsetTop-offsetTop(el)-elH
				}
				else if(animation == "flyTopRight" || animation == "flyTopRightWord")
				{
					elprops.posLeft = -offsetLeft(el)+docWidth
					elprops.posTop = scrollOffsetTop-offsetTop(el)-elH
				}
				else if(animation == "flyCorner")
				{
					elprops.posLeft = docWidth*0.2-offsetLeft(el)
					
					elprops.posTop = scrollOffsetTop-offsetTop(el)+docHeight 
				}
				else if(animation == "flyBottomLeft")
				{
					elprops.posLeft = -offsetLeft(el)-elW
					elprops.posTop = scrollOffsetTop-offsetTop(el)+docHeight
				}
				else if(animation == "flyBottomRight" || animation == "flyBottomRightWord")
				{
					elprops.posLeft = -offsetLeft(el)+docWidth
					elprops.posTop = scrollOffsetTop-offsetTop(el)+docHeight
				}
				else if(animation == "spiral")
				{
					elprops.posLeft = -offsetLeft(el)+docWidth
					elprops.posTop = scrollOffsetTop-offsetTop(el)+docHeight
				}
				else if((animation.indexOf("waveWords") != -1) || animation=="hopWords")
				{
					if(i)
					{
						prevEl=animateElements[i-1]
						elprops.r = offsetLeft(el)-prevEl.startL
					}
					else
						elprops.r = offsetLeft(el)
				}
				else if(animation == "wipeLR" || animation == "wipeMID")
				{
					if (ie4 && elprops.position=="absolute")
					{
						el.sizeW=el.offsetWidth
						elprops.clip="rect(0 0 0 0)"
					}
					else if (!ie4)
					{
						el.sizeW=el.clip.width
						el.clip.width=0
					}
				}
				else if(animation == "wipeTB")
				{
					if (ie4 && elprops.position=="absolute")
					{
						elprops.clip="rect(0 0 0 0)"
					}
					else if(!ie4)
					{
						el.sizeH=el.clip.height
						el.clip.height=0
					}
				}
				else if(animation == "zoomIn")
				{
					elprops.posLeft = 0
					elprops.posTop = 0
				}
				else if(animation == "zoomOut")
				{
					elprops.posLeft = 0
					elprops.posTop = 0
				}
				else
				{
					continue
				}
				if(!outEffect)
				{
					el.initLeft = elprops.posLeft
					el.initTop  = elprops.posTop
					el.endLeft  = 0
					el.endTop   = 0
					elprops.visibility = "hidden"
				}
				else
				{
					el.initLeft = 0
					el.initTop  = 0
					el.endLeft = elprops.posLeft
					el.endTop  = elprops.posTop
					elprops.posTop = 0
					elprops.posLeft = 0
				}
				if(!ie4)
				{
					elprops.left=elprops.initLeft
					elprops.top =elprops.initTop
				}
				animateElements[i++] = el
			}
		}
		if(animateElements.length > 0)
		{
			if(outEffect)
				window.setTimeout("animate(1);", speed, "Javascript")
			else
				window.setTimeout("animate(0);", speed, "Javascript")
		}
	}
	function offsetLeft(el)
	{
		if(ie4)
		{
			x = el.offsetLeft
			for (e = el.offsetParent; e; e = e.offsetParent)
				x += e.offsetLeft
			return x
		}
		else
		{
			x = el.pageX
			return x
		}
	} 
	function offsetTop(el)
	{
		if(ie4)
		{
			y = el.offsetTop
			for (e = el.offsetParent; e; e = e.offsetParent)
				y += e.offsetTop;
			return y
		}
		else
		{
			y = el.pageY
			return y	
		}
	}
	function startWord(ih, i)
	{
		for(tag = false; i < ih.length; i++)
		{
			c = ih.charAt(i)
			if(c == '<')
			{
				if(ih.substring(i+1, i+4) == "IMG")
				return i;
				tag = true
			}
			if(!tag)
				return i
			if(c == '>')
				tag = false
		}
		return -1
	}
	function endWord(ih, i)
	{
		nonSpace = false
		space = false
		img = false
		if(ih.charAt(i) == '<')
		{
			img = true
			i++;
		}
		while(i < ih.length)
		{
			c = ih.charAt(i)
			if(c != ' ')
				nonSpace = true
			if(img && c == '>')
				img = false;
			if(nonSpace && !img && c == ' ')
				space = true
			if(c == '<')
				return i
			if(space && c != ' ')
				return i
			i++
		}
		return -1
	}
	function outWord(ih, i1, i2, dyn, anim, srcID)
	{
		if(dyn)
			if(!outEffect)
				outString += "<SPAN ID=\"" +  fpanimationPrefix + anim + "FP\" style=\"position: relative; visibility: hidden;\">"
			else
				outString += "<SPAN SRCID=\"" + srcID + "\"ID=\"" +  fpanimationPrefix + anim + "FP\" style=\"position: relative;\">"
		outString += ih.substring(i1, i2)
		if(dyn)
			outString += "</SPAN>"
	}
	function outWordAlt(ih, i1, i2, dyn, anim, altcnt)
	{
		if(dyn)
		{
			if(altcnt%2)
				outString += "<SPAN ID=\"" +  fpanimationPrefix + anim + "LFP\" style=\"position: relative;  visibility: hidden;\">"
			else
				outString += "<SPAN ID=\"" +  fpanimationPrefix + anim + "RFP\" style=\"position: relative;  visibility: hidden;\">"
		}
			
		outString += ih.substring(i1, i2)
		if(dyn)
			outString += "</SPAN>"
	}
	function animate(animOut)
	{
		el = animateElements[currentElement]
		if(animOut)
			animationId = el.id.substring(9,el.id.length);
		else
			animationId = el.id.substring(6,el.id.length);
		animation=remSuffix(animationId)
		if (ie4)
			elprops=el.style
		else
			elprops=el

		if(!step && !animOut)
			elprops.visibility="visible"
		step++
		if(animation == "spiral")
		{
			steps = stepsSpiral
			v = step/steps
			rf = 1.0 - v
			t = v * 2.0*Math.PI
			rx = Math.max(Math.abs(el.initLeft), 200)
			ry = Math.max(Math.abs(el.initTop),  200)
			elprops.posLeft = Math.ceil(-rf*Math.cos(t)*rx)
			elprops.posTop  = Math.ceil(-rf*Math.sin(t)*ry)
		}
		else if(animation == "waveWordsL" || animation=="hopWords" || animation == "waveWords")
		{
			steps = stepsSpiralWord
			v = step/steps
			rf = (1.0 - v)
			t = v * 1.0*Math.PI
			elprops.posLeft = Math.ceil(-rf*Math.cos(t)*elprops.r)
			elprops.posTop  = Math.ceil(-rf*Math.sin(t)*elprops.r)
		}
		else if(animation == "waveWordsR")
		{
			steps = stepsSpiralWord
			v = step/steps
			rf = (1.0 - v)
			t = v * 1.0*Math.PI
			elprops.posLeft = Math.ceil(-rf*Math.cos(t)*elprops.r)
			elprops.posTop  = Math.ceil( rf*Math.sin(t)*elprops.r)
		}
		else if(animation == "zoomIn")
		{
			steps = stepsZoom
			elprops.fontSize = Math.ceil(50+50*step/steps) + "%"
			elprops.posLeft = 0
		}
		else if(animation == "zoomOut")
		{
			steps = stepsZoom
			fontSz=Math.ceil(100+200*(steps-step)/steps) + "%"
			elprops.fontSize = fontSz
			elprops.posLeft = 0
		}
		else if(animation == "elasticRight")
		{
			steps = stepsElastic
			v = step/steps
			rf=Math.exp(-v*7)
			t = v * 1.5*Math.PI
			rx =Math.abs(el.initLeft)
			elprops.posLeft = rf*Math.cos(t)*rx
			elprops.posTop  = 0
		}
		else if(animation == "elasticBottom")
		{
			steps = stepsElastic
			v = step/steps
			rf=Math.exp(-v*7)
			t = v * 2.5*Math.PI
			ry =Math.abs(el.initTop)
			elprops.posLeft = 0
			elprops.posTop  = rf*Math.cos(t)*ry
		}
		else if(animation == "wipeLR")
		{
			steps = stepsElastic
			if(ie4 && elprops.position=="absolute")
				elprops.clip = "rect(0 "+ step/steps*100 +"% 100% 0)"
			else if (!ie4)
			{
				elprops.clip.right=step/steps*el.sizeW
			}
		}
		else if(animation == "wipeTB")
		{
			steps = stepsElastic
			if(ie4 && elprops.position=="absolute")
				elprops.clip = "rect(0 100% "+step/steps*el.offsetHeight+"px 0)"
			else
				elprops.clip.bottom=step/steps*el.sizeH
		}
		else if(animation == "wipeMID")
		{
			steps = stepsElastic
			if(ie4 && elprops.position=="absolute")
			{
				elprops.clip = "rect(0 "+el.sizeW/2*(1+step/steps)+"px 100% "+el.sizeW/2*(1-step/steps)+")"
			}
			else if(!ie4)
			{
				elprops.clip.right=el.sizeW/2*(1+step/steps)
				elprops.clip.left=el.sizeW/2*(1-step/steps)
			}
		}
		else if(animation == "flyCorner")
		{
			if(!cornerPhase)
			{
				steps = stepsElastic/2
				v = step/steps
				rf=Math.exp(-v*7)
				t = v * 2.5*Math.PI
				ry =Math.abs(el.initTop)
				elprops.posTop  = rf*Math.cos(t)*ry
			}
			else
			{
				steps = stepsFly
				dl = el.initLeft / steps
				elprops.posLeft = elprops.posLeft - dl
				elprops.posTop = 0
			}
		}
		else
		{
			steps = stepsFly
			if(animation == "dropWord" || animation == "flyTopRightWord" || animation == "flyBottomRightWord")
				steps = stepsWord
			dl = (el.endLeft - el.initLeft) / steps
			dt = (el.endTop  - el.initTop)  / steps
			elprops.posLeft = elprops.posLeft + dl
			elprops.posTop = elprops.posTop + dt
		}
		if (step >= steps) 
		{
			if(!(animation == "wipeLR"	||
				animation  == "wipeTB"	||
				animation  == "wipeMID"	||
				(animation == "flyCorner" && !cornerPhase)))
			{
				elprops.posLeft = el.endLeft
				elprops.posTop = el.endTop
			}
			if(animOut)
			{
				elprops.visibility="hidden"
			}

			step = 0
			if(animation=="flyCorner" && !cornerPhase)
				cornerPhase=1
			else
			{
				cornerPhase=0
				currentElement++
			}

		}
		if(!ie4)
		{
			elprops.left=elprops.posLeft
			elprops.top =elprops.posTop
		}
		if(currentElement < animateElements.length)
		{
			if(animOut)
				window.setTimeout("animate(1);", speed, "Javascript")
			else
				window.setTimeout("animate(0);", speed, "Javascript")
		}
		else
			currentElement=0
	}
	function rollIn(el)
	{
		var ms = navigator.appVersion.indexOf("MSIE")
		ie4 = (ms>0) && (parseInt(navigator.appVersion.substring(ms+5, ms+6)) >= 4)
		if(ie4)
		{
			el.initstyle=el.style.cssText;el.style.cssText=el.fprolloverstyle
		}
	}
	function rollOut(el)
	{
		var ms = navigator.appVersion.indexOf("MSIE")
		ie4 = (ms>0) && (parseInt(navigator.appVersion.substring(ms+5, ms+6)) >= 4)
		if(ie4)
		{
			el.style.cssText=el.initstyle
		}
	}
	function clickSwapStyle(el)
	{
		var ms = navigator.appVersion.indexOf("MSIE")
		ie4 = (ms>0) && (parseInt(navigator.appVersion.substring(ms+5, ms+6)) >= 4)
		if(ie4)
		{
			ts=el.style.cssText
			el.style.cssText=el.fprolloverstyle
			el.fprolloverstyle=ts
		}
	}
	function clickSwapImg(el)
	{
        if(document.all || document.layers)
        {
    		ts=el.src
    		el.src=el.lowsrc
    		el.lowsrc=ts
        }
	}
//-->


