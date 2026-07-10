const elements =
document.querySelectorAll(".grid div,.steps div");


window.addEventListener("scroll",()=>{

elements.forEach(el=>{

let top =
el.getBoundingClientRect().top;


if(top < window.innerHeight-80){

el.style.opacity="1";

el.style.transform="translateY(0)";

}

});

});