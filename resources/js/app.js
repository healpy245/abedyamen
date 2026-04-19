import './bootstrap';

// Enhanced motion effects for the home page
document.addEventListener('DOMContentLoaded', function() {
    // Add scroll-triggered animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-fade-in');
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Observe all motion elements
    const motionElements = document.querySelectorAll('.motion-card, .motion-value-prop, .motion-service, .motion-text');
    motionElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
        observer.observe(el);
    });

    // Enhanced hover effects for icons
    const motionIcons = document.querySelectorAll('.motion-icon');
    motionIcons.forEach(icon => {
        icon.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1) rotate(3deg)';
        });
        
        icon.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1) rotate(0deg)';
        });
    });

    // Enhanced button hover effects
    const motionButtons = document.querySelectorAll('.motion-button, .motion-cta');
    motionButtons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05) translateY(-2px)';
            this.style.boxShadow = '0 10px 25px rgba(251, 146, 60, 0.3)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1) translateY(0)';
            this.style.boxShadow = '';
        });
    });

    // Parallax effect for hero section
    const heroSection = document.querySelector('.bg-gradient-to-br');
    if (heroSection) {
        window.addEventListener('scroll', function() {
            const scrolled = window.pageYOffset;
            const rate = scrolled * -0.5;
            heroSection.style.transform = `translateY(${rate}px)`;
        });
    }

    // Add floating animation to value proposition cards
    const valueProps = document.querySelectorAll('.motion-value-prop');
    valueProps.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.2}s`;
        card.classList.add('animate-float');
    });

    // Add pulse animation to service icons on hover
    const serviceIcons = document.querySelectorAll('.motion-service .motion-icon');
    serviceIcons.forEach(icon => {
        icon.addEventListener('mouseenter', function() {
            this.classList.add('animate-pulse-slow');
        });
        
        icon.addEventListener('mouseleave', function() {
            this.classList.remove('animate-pulse-slow');
        });
    });

    // Add glow effect to CTA buttons
    const ctaButtons = document.querySelectorAll('.motion-cta');
    ctaButtons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.classList.add('animate-glow');
        });
        
        button.addEventListener('mouseleave', function() {
            this.classList.remove('animate-glow');
        });
    });

    // Smooth scroll for anchor links
    const anchorLinks = document.querySelectorAll('a[href^="#"]');
    anchorLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            const targetElement = document.querySelector(targetId);
            
            if (targetElement) {
                targetElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Add entrance animation to hero content
    const heroContent = document.querySelector('.text-center.lg\\:text-left');
    if (heroContent) {
        heroContent.style.opacity = '0';
        heroContent.style.transform = 'translateY(30px)';
        heroContent.style.transition = 'opacity 1s ease-out, transform 1s ease-out';
        
        setTimeout(() => {
            heroContent.style.opacity = '1';
            heroContent.style.transform = 'translateY(0)';
        }, 300);
    }

    // Add staggered animation to service cards
    const serviceCards = document.querySelectorAll('.motion-service');
    serviceCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = `opacity 0.6s ease-out ${index * 0.1}s, transform 0.6s ease-out ${index * 0.1}s`;
        
        setTimeout(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 500 + (index * 100));
    });
});
